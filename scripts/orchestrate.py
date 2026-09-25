#!/usr/bin/env python3
"""Small, deterministic dispatcher for this repository's coding agents."""

from __future__ import annotations

import argparse
import fcntl
import json
import math
import os
import re
import select
import shutil
import subprocess
import sys
import threading
import time
import uuid
from collections import deque
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterator


ROOT = Path(__file__).resolve().parents[1]
CONFIG_DIR = ROOT / "orchestrator"
RUNTIME = ROOT / ".orchestrator"
TASK_ID = re.compile(r"^[a-z][a-z0-9-]*$")


class OrchestratorError(Exception):
    pass


def read_json(path: Path) -> dict[str, Any]:
    with path.open(encoding="utf-8") as stream:
        return json.load(stream)


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    with temporary.open("w", encoding="utf-8") as stream:
        json.dump(value, stream, ensure_ascii=False, indent=2)
        stream.write("\n")
    os.replace(temporary, path)


def load_config() -> tuple[dict[str, Any], dict[str, dict[str, Any]]]:
    policy = read_json(CONFIG_DIR / "policy.json")
    raw_tasks = read_json(CONFIG_DIR / "tasks.json")["tasks"]
    tasks = {task["id"]: task for task in raw_tasks}
    if len(tasks) != len(raw_tasks):
        raise OrchestratorError("Duplicate task id in tasks.json")
    for task in raw_tasks:
        if not TASK_ID.fullmatch(task["id"]):
            raise OrchestratorError(f"Invalid task id: {task['id']}")
        if task["module"] not in policy["modules"]:
            raise OrchestratorError(f"Unknown module in {task['id']}")
        if task["kind"] not in policy["routes"]:
            raise OrchestratorError(f"Unknown kind in {task['id']}")
        for dependency in task.get("depends_on", []):
            if dependency not in tasks or dependency == task["id"]:
                raise OrchestratorError(f"Invalid dependency {dependency} in {task['id']}")
    visited: set[str] = set()
    visiting: set[str] = set()

    def visit(task_id: str) -> None:
        if task_id in visiting:
            raise OrchestratorError(f"Dependency cycle at {task_id}")
        if task_id in visited:
            return
        visiting.add(task_id)
        for dependency in tasks[task_id].get("depends_on", []):
            visit(dependency)
        visiting.remove(task_id)
        visited.add(task_id)

    for task_id in tasks:
        visit(task_id)
    return policy, tasks


def route(policy: dict[str, Any], task: dict[str, Any], provider: str | None = None,
          model: str | None = None) -> dict[str, str]:
    rule = policy["routes"][task["kind"]]
    if model and not provider:
        matches = [name for name, models in policy["allowed_models"].items() if model in models]
        if len(matches) != 1:
            raise OrchestratorError(f"Model {model} is not allowed")
        provider = matches[0]
    selected = provider or task.get("preferred_provider") or rule["default_provider"]
    if selected == "ollama":
        local = policy["ollama"]
        if model and model != local["model"]:
            raise OrchestratorError("Ollama accepts only the configured local model")
        if not local["enabled"] or not local["model"]:
            raise OrchestratorError("Ollama is disabled; set enabled and model in policy.json")
        if task["kind"] not in local["allowed_kinds"]:
            raise OrchestratorError(f"Ollama is not allowed for kind {task['kind']}")
        return {"provider": "ollama", "model": local["model"], "effort": ""}
    if selected not in policy["providers"] or selected not in rule:
        raise OrchestratorError(f"Provider {selected} cannot run {task['kind']}")
    configured = rule[selected]
    chosen_model = model or configured["model"]
    if chosen_model not in policy["allowed_models"][selected]:
        raise OrchestratorError(f"Model {chosen_model} is not allowed for {selected}")
    effort = "" if chosen_model == "claude-haiku-4-5" else configured["effort"]
    return {"provider": selected, "model": chosen_model, "effort": effort}


def _percent(value: Any, label: str) -> float:
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(value):
        raise OrchestratorError(f"Invalid usage percent for {label}")
    if not 0 <= value <= 100:
        raise OrchestratorError(f"Usage percent outside 0..100 for {label}")
    return float(value)


def _usage_snapshot(provider: str, used: dict[str, Any], source: str,
                    short_window: str, weekly_window: str) -> dict[str, Any]:
    windows = {name: {"used_percent": _percent(value, f"{provider}.{name}"),
                      "remaining_percent": 100 - _percent(value, f"{provider}.{name}")}
               for name, value in used.items()}
    if not windows:
        raise OrchestratorError(f"No usage windows reported by {provider}")
    if short_window not in windows or weekly_window not in windows:
        raise OrchestratorError(f"Missing short or weekly usage window for {provider}")
    return {"provider": provider, "source": source, "checked_at": datetime.now(timezone.utc).isoformat(),
            "windows": windows, "remaining_percent": windows[short_window]["remaining_percent"],
            "weekly_remaining_percent": windows[weekly_window]["remaining_percent"]}


def _codex_rpc_response(process: subprocess.Popen[str], request: dict[str, Any],
                        timeout: float = 20) -> dict[str, Any]:
    assert process.stdin is not None and process.stdout is not None
    process.stdin.write(json.dumps(request) + "\n")
    process.stdin.flush()
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if not select.select([process.stdout], [], [], max(0, deadline - time.monotonic()))[0]:
            break
        line = process.stdout.readline()
        if not line:
            break
        try:
            response = json.loads(line)
        except json.JSONDecodeError:
            continue
        if response.get("id") == request["id"]:
            if "error" in response:
                raise OrchestratorError(f"Codex usage request failed: {response['error']}")
            return response.get("result") or {}
    raise OrchestratorError("Codex usage request timed out or returned no response")


def codex_usage() -> dict[str, Any]:
    """Read subscription windows through the installed Codex CLI app-server protocol."""
    try:
        process = subprocess.Popen(["codex", "app-server", "--stdio"], cwd=ROOT, stdin=subprocess.PIPE,
                                   stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, text=True, bufsize=1)
    except OSError as error:
        raise OrchestratorError(f"Cannot start Codex usage check: {error}") from error
    try:
        _codex_rpc_response(process, {"id": 1, "method": "initialize",
                                      "params": {"clientInfo": {"name": "ai-photobank-orchestrator", "version": "1"}}})
        data = _codex_rpc_response(process, {"id": 2, "method": "account/rateLimits/read",
                                             "params": {"includeResetCredits": False}})
        return parse_codex_usage(data)
    finally:
        process.terminate()
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()


def parse_codex_usage(data: dict[str, Any]) -> dict[str, Any]:
    if data.get("ordinaryUsageAllowed") is not True:
        raise OrchestratorError("Codex ordinary subscription usage is blocked or unavailable")
    limits = (data.get("rateLimitsByLimitId") or {}).get("codex") or data.get("rateLimits") or {}
    used = {name: window["usedPercent"] for name in ("primary", "secondary")
            if isinstance(window := limits.get(name), dict) and window.get("usedPercent") is not None}
    if set(used) != {"primary", "secondary"}:
        raise OrchestratorError("Codex did not report both subscription usage windows")
    return _usage_snapshot("codex", used, "codex app-server account/rateLimits/read",
                           "primary", "secondary")


def claude_usage() -> dict[str, Any]:
    """Read the local /usage command without asking a model to generate an answer."""
    try:
        result = subprocess.run(["claude", "--print", "--output-format", "json", "--max-turns", "1",
                                 "--no-session-persistence", "/usage"],
                                cwd=ROOT, capture_output=True, text=True, timeout=30, check=False)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise OrchestratorError(f"Cannot read Claude usage: {error}") from error
    if result.returncode:
        raise OrchestratorError(f"Claude usage command failed (exit {result.returncode})")
    try:
        data = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise OrchestratorError("Claude usage command returned invalid JSON") from error
    return parse_claude_usage(data)


def parse_claude_usage(data: dict[str, Any]) -> dict[str, Any]:
    if data.get("local_command") != "usage" or data.get("num_turns") != 0 or data.get("is_error"):
        raise OrchestratorError("Claude did not return a local /usage result")
    message = data.get("result") or ""
    patterns = {"session": r"Current session:\s*([0-9]+(?:\.[0-9]+)?)% used",
                "week": r"Current week \(all models\):\s*([0-9]+(?:\.[0-9]+)?)% used"}
    used = {}
    for name, pattern in patterns.items():
        match = re.search(pattern, message)
        if not match:
            raise OrchestratorError(f"Claude /usage did not report {name} subscription percentage")
        used[name] = float(match.group(1))
    snapshot = _usage_snapshot("claude", used, "claude -p /usage", "session", "week")
    fable = re.search(r"Current week \(Fable\):\s*([0-9]+(?:\.[0-9]+)?)% used", message)
    if fable:
        snapshot["fable_remaining_percent"] = 100 - _percent(float(fable.group(1)), "claude.fable")
    return snapshot


def read_usage(providers: list[str]) -> tuple[dict[str, dict[str, Any]], dict[str, str]]:
    snapshots: dict[str, dict[str, Any]] = {}
    errors: dict[str, str] = {}
    probes = {"codex": codex_usage, "claude": claude_usage}
    for provider in providers:
        if provider not in probes:
            continue
        try:
            snapshots[provider] = probes[provider]()
        except OrchestratorError as error:
            errors[provider] = str(error)
    return snapshots, errors


def choose_route(policy: dict[str, Any], task: dict[str, Any], provider: str | None,
                 model: str | None, usage: dict[str, dict[str, Any]],
                 errors: dict[str, str]) -> tuple[dict[str, str], str]:
    if provider or model:
        selected = route(policy, task, provider, model)
        if selected["provider"] in errors:
            raise OrchestratorError(f"Usage unavailable for {selected['provider']}: {errors[selected['provider']]}")
        if selected["provider"] in usage:
            snapshot = usage[selected["provider"]]
            if snapshot["remaining_percent"] <= 0 or snapshot["weekly_remaining_percent"] <= 0:
                raise OrchestratorError(f"No 5-hour or weekly subscription usage remains for {selected['provider']}")
        if selected["model"] == "claude-fable-5-1":
            fable = usage.get("claude", {}).get("fable_remaining_percent")
            if fable is None or fable <= 0:
                raise OrchestratorError("Claude Fable subscription usage is unavailable or exhausted")
        return selected, "explicit model/provider selection"
    preferred = task.get("preferred_provider") or policy["routes"][task["kind"]]["default_provider"]
    minimum = policy["usage_routing"]["minimum_remaining_percent"]
    available = {name: snapshot["remaining_percent"] for name, snapshot in usage.items()
                 if name in policy["providers"] and snapshot["remaining_percent"] >= minimum
                 and snapshot["weekly_remaining_percent"] >= minimum}
    if not available:
        raise OrchestratorError(f"No provider has verified usage above {minimum}%: {errors}")
    best = max(available, key=available.get)
    if preferred in available and available[best] - available[preferred] < policy["usage_routing"]["switch_margin_percent"]:
        best = preferred
    selected = route(policy, task, best)
    reason = f"5-hour subscription headroom: {best} {available[best]:g}% remaining"
    return selected, reason


def select_for_task(policy: dict[str, Any], task: dict[str, Any], provider: str | None,
                    model: str | None, skip_usage_check: bool = False
                    ) -> tuple[dict[str, str], str, dict[str, dict[str, Any]], dict[str, str]]:
    requested = route(policy, task, provider, model) if provider or model else None
    if skip_usage_check:
        if requested is None:
            raise OrchestratorError("--skip-usage-check requires --provider or --model")
        return requested, "explicit selection; usage check skipped", {}, {}
    if requested and requested["provider"] == "ollama":
        return requested, "explicit local provider", {}, {}
    providers = [requested["provider"]] if requested else policy["providers"]
    usage, errors = read_usage(providers)
    selected, reason = choose_route(policy, task, provider, model, usage, errors)
    return selected, reason, usage, errors


def checkpoint_due(snapshot: dict[str, Any], policy: dict[str, Any], model: str = "") -> bool:
    monitor = policy["usage_monitor"]
    return (snapshot["remaining_percent"] <= monitor["checkpoint_five_hour_remaining_percent"] or
            snapshot["weekly_remaining_percent"] <= monitor["checkpoint_weekly_remaining_percent"] or
            (model == "claude-fable-5-1" and snapshot.get("fable_remaining_percent", 0) <=
             monitor["checkpoint_weekly_remaining_percent"]))


def checkpoint_request_path(worktree: str | Path) -> Path:
    return Path(worktree) / ".orchestrator" / "checkpoint-request.json"


def save_checkpoint_request(run: dict[str, Any], reason: str,
                            usage: dict[str, Any] | None = None) -> Path:
    path = checkpoint_request_path(run["worktree"])
    write_json(path, {"run_id": run["run_id"], "task_id": run["task_id"], "reason": reason,
                      "requested_at": datetime.now(timezone.utc).isoformat(), "usage": usage})
    return path


@contextmanager
def state_lock() -> Iterator[dict[str, Any]]:
    RUNTIME.mkdir(parents=True, exist_ok=True, mode=0o700)
    RUNTIME.chmod(0o700)
    lock_path = RUNTIME / "state.lock"
    with lock_path.open("a+") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        state_path = RUNTIME / "state.json"
        state = read_json(state_path) if state_path.exists() else {"tasks": {}, "runs": {}}
        yield state
        write_json(state_path, state)
        fcntl.flock(lock, fcntl.LOCK_UN)


def git(*args: str, cwd: Path = ROOT, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    result = subprocess.run(["git", *args], cwd=cwd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if check and result.returncode:
        message = result.stderr.decode("utf-8", errors="replace").strip()
        raise OrchestratorError(message or f"git {' '.join(args)} failed")
    return result


def git_ready() -> str:
    if git("rev-parse", "--show-toplevel", check=False).returncode:
        raise OrchestratorError("Git repository is required for isolated worktrees")
    head = git("rev-parse", "HEAD", check=False)
    if head.returncode:
        raise OrchestratorError("Create the initial Git commit before dispatch")
    if git("status", "--porcelain", "--untracked-files=all").stdout:
        raise OrchestratorError("Commit or stash changes in the main checkout before dispatch")
    return head.stdout.decode().strip()


def task_state(state: dict[str, Any], task_id: str) -> str:
    return state["tasks"].get(task_id, {}).get("status", "queued")


def readiness(task: dict[str, Any], policy: dict[str, Any], state: dict[str, Any]) -> list[str]:
    reasons = []
    current = task_state(state, task["id"])
    if current != "queued" and current != "failed":
        reasons.append(f"status={current}")
    for dependency in task.get("depends_on", []):
        if task_state(state, dependency) != "accepted":
            reasons.append(f"dependency {dependency} is {task_state(state, dependency)}")
    owned = set(policy["modules"][task["module"]]["owned_paths"])
    for other_id, record in state["tasks"].items():
        if other_id == task["id"] or record.get("status") not in {"running", "checkpointing", "checkpointed", "needs_review", "scope_violation"}:
            continue
        other_module = record.get("module")
        if other_module and owned.intersection(policy["modules"][other_module]["owned_paths"]):
            reasons.append(f"owned paths occupied by {other_id}")
    return reasons


def changed_paths(worktree: Path, base: str) -> list[str]:
    tracked = git("diff", "--name-only", "-z", base, cwd=worktree).stdout
    untracked = git("ls-files", "--others", "--exclude-standard", "-z", cwd=worktree).stdout
    return sorted({entry.decode("utf-8", errors="replace") for entry in (tracked + untracked).split(b"\0") if entry})


def outside_ownership(paths: list[str], owned_paths: list[str]) -> list[str]:
    return [path for path in paths if not any(path == prefix or path.startswith(prefix) for prefix in owned_paths)]


def build_prompt(task: dict[str, Any], policy: dict[str, Any]) -> str:
    module = policy["modules"][task["module"]]
    dependencies = ", ".join(task.get("depends_on", [])) or "none"
    return f"""You are implementing one assigned AI Photobank task in an isolated Git worktree.

Task: {task['id']} — {task['title']}
Module: {task['module']}
Task kind: {task['kind']}
Dependencies accepted: {dependencies}
Owned paths: {', '.join(module['owned_paths'])}

Read .agents, {module['brief']}, and the relevant sections of docs/technical-specification.md.
For API behavior, read applicable contracts in contracts/. Do not invent or change an inter-module contract.
Do not modify paths outside the owned paths above. If required contracts are missing or inconsistent,
stop the dependent implementation and explain the exact blocker in your final report.

Assigned work: {task['instructions']}

Run focused checks. In the final report list changed files, checks actually run, contract compliance,
and any blockers. Do not merge branches or push to a remote repository.

After each meaningful implementation step, check .orchestrator/checkpoint-request.json in this worktree.
If it exists, stop starting new work. Finish the current safe edit, then give a final checkpoint report
with headings: Completed, Remaining, Checks, Blockers. Keep partial changes in this worktree.
Do not write the checkpoint artifact yourself; the orchestrator saves your report.
"""


CHECKPOINT_MESSAGE = """Subscription capacity is approaching its limit. Stop starting new work now.
Finish the current safe edit, then provide a concise checkpoint report with these headings:
Completed, Remaining, Checks, Blockers. Mention any incomplete file and the exact next step.
Keep all partial code in this worktree. Do not commit, merge, or push."""


def build_resume_prompt(task: dict[str, Any], policy: dict[str, Any], checkpoint: str) -> str:
    return (build_prompt(task, policy) +
            "\nResume the existing work in this same worktree. Inspect the partial diff before editing. "
            "Use this checkpoint as context, verify its claims against the files, and continue from the "
            "first unfinished step. Do not restart or discard existing changes.\n\n"
            "Previous checkpoint:\n" + checkpoint)


def agent_command(run: dict[str, Any], summary: Path) -> list[str]:
    provider, model, effort = run["provider"], run["model"], run["effort"]
    worktree = run["worktree"]
    if provider in {"codex", "ollama"}:
        command = ["codex", "--sandbox", "workspace-write", "--cd", worktree]
        if provider == "ollama":
            command += ["--oss", "--local-provider", "ollama"]
        else:
            command += ["--config", f'model_reasoning_effort="{effort}"']
        command += ["exec"]
        if run.get("resume_session_id"):
            command += ["resume"]
        command += ["--model", model, "--json", "--output-last-message", str(summary)]
        if run.get("resume_session_id"):
            command += [run["resume_session_id"]]
        return command + ["-"]
    if provider == "claude":
        sandbox_settings = {"sandbox": {"enabled": True, "allowUnsandboxedCommands": False,
                                        "failIfUnavailable": True}}
        command = ["claude", "--print", "--model", model, "--output-format", "stream-json",
                   "--input-format", "stream-json", "--replay-user-messages", "--verbose",
                   "--permission-mode", "acceptEdits",
                   "--permission-prompts", "none", "--settings", json.dumps(sandbox_settings)]
        if run.get("resume_session_id"):
            command += ["--resume", run["resume_session_id"]]
        if effort:
            command += ["--effort", effort]
        return command
    raise OrchestratorError(f"Unsupported provider {provider}")


def _claude_input(message: str) -> str:
    return json.dumps({"type": "user", "message": {"role": "user", "content": message}},
                      ensure_ascii=False) + "\n"


def _read_agent_output(process: subprocess.Popen[str], log_path: Path, run_id: str,
                       observed: dict[str, Any]) -> None:
    assert process.stdout is not None
    with log_path.open("w", encoding="utf-8") as log:
        for line in process.stdout:
            log.write(line)
            log.flush()
            try:
                event = json.loads(line)
            except json.JSONDecodeError:
                continue
            session_id = event.get("thread_id") if event.get("type") == "thread.started" else event.get("session_id")
            if session_id and not observed["session_id"]:
                observed["session_id"] = session_id
                with state_lock() as state:
                    state["runs"][run_id]["agent_session_id"] = session_id
            if event.get("type") == "result":
                observed["result_count"] += 1
                observed["last_result"] = str(event.get("result") or "")
            item = event.get("item") or {}
            if item.get("type") == "agent_message" and item.get("text"):
                observed["last_result"] = str(item["text"])


def _checkpoint_report_complete(report: str) -> bool:
    lowered = report.lower()
    return "completed" in lowered and "remaining" in lowered


def _last_result_from_log(log_path: Path) -> str:
    if not log_path.exists():
        return ""
    last = ""
    for line in log_path.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        if event.get("type") == "result" and event.get("result"):
            last = str(event["result"])
        item = event.get("item") or {}
        if item.get("type") == "agent_message" and item.get("text"):
            last = str(item["text"])
    return last


def _checkpoint_followup(run: dict[str, Any], session_id: str, run_dir: Path,
                         log_path: Path, timeout: int) -> tuple[str, str | None]:
    followup = dict(run, resume_session_id=session_id, prompt=CHECKPOINT_MESSAGE)
    followup_summary = run_dir / "checkpoint-summary.txt"
    command = agent_command(followup, followup_summary)
    input_text = _claude_input(CHECKPOINT_MESSAGE) if run["provider"] == "claude" else CHECKPOINT_MESSAGE
    try:
        with log_path.open("a", encoding="utf-8") as log:
            log.write("\n--- checkpoint follow-up turn ---\n")
            result = subprocess.run(command, cwd=run["worktree"], input=input_text,
                                    text=True, stdout=log, stderr=subprocess.STDOUT,
                                    timeout=timeout, check=False)
        report = (followup_summary.read_text(encoding="utf-8") if followup_summary.exists()
                  else _last_result_from_log(log_path))
        failure = None if result.returncode == 0 else f"Checkpoint turn exited {result.returncode}"
        return report, failure
    except (OSError, subprocess.TimeoutExpired) as error:
        return "", f"Checkpoint turn failed: {error}"


def _write_checkpoint(run: dict[str, Any], report: str, changes: list[str],
                      reason: str, usage: dict[str, Any] | None,
                      followup_error: str | None) -> Path:
    task_dir = RUNTIME / "tasks" / run["task_id"]
    task_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    task_dir.chmod(0o700)
    artifact = task_dir / "checkpoint.md"
    completed = _checkpoint_report_complete(report)
    lines = [f"# Checkpoint: {run['task_id']}", "",
             f"- Run: {run['run_id']}",
             f"- Provider/model: {run['provider']} / {run['model']}",
             f"- Branch: {run['branch']}",
             f"- Worktree: {run['worktree']}",
             f"- Native session: {run.get('agent_session_id') or 'unavailable'}",
             f"- Reason: {reason}",
             f"- Agent report complete: {'yes' if completed else 'no'}",
             f"- Follow-up error: {followup_error or 'none'}", "",
             f"- Agent log: {RUNTIME / 'runs' / run['run_id'] / 'agent.log'}",
             f"- Base commit: {run['base']}", "",
             "## Changed files observed by Git", ""]
    lines += [f"- {path}" for path in changes] or ["- None"]
    lines += ["", "## Agent report", "", report.strip() or
              "No agent report was available. Inspect the diff and agent.log before resuming.", ""]
    artifact.write_text("\n".join(lines), encoding="utf-8")
    write_json(task_dir / "checkpoint.json",
               {"task_id": run["task_id"], "run_id": run["run_id"], "provider": run["provider"],
                "model": run["model"], "branch": run["branch"], "worktree": run["worktree"],
                "agent_session_id": run.get("agent_session_id"), "reason": reason,
                "agent_report_complete": completed, "changed_paths": changes,
                "usage": usage, "followup_error": followup_error,
                "saved_at": datetime.now(timezone.utc).isoformat()})
    local = Path(run["worktree"]) / ".orchestrator" / "checkpoint.md"
    local.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    local.write_text(artifact.read_text(encoding="utf-8"), encoding="utf-8")
    return artifact


def execute(run_id: str) -> None:
    with state_lock() as state:
        run = dict(state["runs"][run_id])
    run["run_id"] = run_id
    run_dir = RUNTIME / "runs" / run_id
    run_dir.mkdir(parents=True, exist_ok=True)
    summary = run_dir / "summary.txt"
    log_path = run_dir / "agent.log"
    observed: dict[str, Any] = {"session_id": None, "result_count": 0, "last_result": ""}
    exit_code = 1
    failure = None
    checkpoint_requested = False
    checkpoint_reason = ""
    checkpoint_usage = None
    checkpoint_path = None
    process: subprocess.Popen[str] | None = None
    reader: threading.Thread | None = None
    try:
        policy, tasks = load_config()
        process = subprocess.Popen(agent_command(run, summary), cwd=run["worktree"],
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   stderr=subprocess.STDOUT, text=True, bufsize=1)
        reader = threading.Thread(target=_read_agent_output,
                                  args=(process, log_path, run_id, observed), daemon=True)
        reader.start()
        assert process.stdin is not None
        process.stdin.write(_claude_input(run["prompt"]) if run["provider"] == "claude" else run["prompt"])
        process.stdin.flush()
        if run["provider"] != "claude":
            process.stdin.close()
        monitor = policy["usage_monitor"]
        next_poll = time.monotonic() + monitor["poll_seconds"]
        sent_signal = False
        while process.poll() is None:
            request_file = checkpoint_request_path(run["worktree"])
            if not checkpoint_requested and request_file.exists():
                request = read_json(request_file)
                if request.get("run_id") == run_id:
                    checkpoint_requested = True
                    checkpoint_reason = str(request.get("reason") or "manual checkpoint")
                    checkpoint_usage = request.get("usage")
                    with state_lock() as state:
                        state["runs"][run_id].update({"status": "checkpointing",
                                                       "checkpoint_requested_at": request.get("requested_at"),
                                                       "checkpoint_reason": checkpoint_reason})
                        state["tasks"][run["task_id"]]["status"] = "checkpointing"
            if not checkpoint_requested and run["provider"] in {"codex", "claude"} and time.monotonic() >= next_poll:
                snapshots, errors = read_usage([run["provider"]])
                next_poll = time.monotonic() + monitor["poll_seconds"]
                if process.poll() is None and snapshots and checkpoint_due(snapshots[run["provider"]], policy, run["model"]):
                    save_checkpoint_request(run, "subscription limit approaching", snapshots[run["provider"]])
                elif errors:
                    with state_lock() as state:
                        state["runs"][run_id]["usage_monitor_error"] = errors.get(run["provider"])
            if checkpoint_requested and not sent_signal:
                if run["provider"] == "claude":
                    try:
                        process.stdin.write(_claude_input(CHECKPOINT_MESSAGE))
                        process.stdin.flush()
                        sent_signal = True
                    except (BrokenPipeError, OSError):
                        sent_signal = True
                elif observed["session_id"] and run["provider"] == "codex":
                    signal = subprocess.run(["codex", "queue", "--thread", observed["session_id"],
                                             "--message", CHECKPOINT_MESSAGE], cwd=run["worktree"],
                                            capture_output=True, text=True, timeout=15, check=False)
                    sent_signal = True
                    if signal.returncode:
                        with state_lock() as state:
                            state["runs"][run_id]["checkpoint_signal_error"] = signal.stderr.strip()
                elif run["provider"] == "ollama":
                    sent_signal = True
            if run["provider"] == "claude" and not process.stdin.closed:
                expected_results = 2 if checkpoint_requested and sent_signal else 1
                complete_checkpoint = (checkpoint_requested and observed["result_count"] > 0 and
                                       _checkpoint_report_complete(observed["last_result"]))
                if complete_checkpoint or observed["result_count"] >= expected_results:
                    process.stdin.close()
            time.sleep(0.25)
        if not checkpoint_requested:
            request_file = checkpoint_request_path(run["worktree"])
            if request_file.exists():
                request = read_json(request_file)
                if request.get("run_id") == run_id:
                    checkpoint_requested = True
                    checkpoint_reason = str(request.get("reason") or "manual checkpoint")
                    checkpoint_usage = request.get("usage")
        reader.join(timeout=5)
        exit_code = process.wait()
        with state_lock() as state:
            run["agent_session_id"] = state["runs"][run_id].get("agent_session_id")
        report = (summary.read_text(encoding="utf-8") if summary.exists() else observed["last_result"])
        followup_error = None
        if checkpoint_requested and not _checkpoint_report_complete(report) and run.get("agent_session_id"):
            fresh, followup_error = _checkpoint_followup(
                run, run["agent_session_id"], run_dir, log_path,
                monitor.get("checkpoint_followup_timeout_seconds", 180))
            report = fresh or report
        changes = changed_paths(Path(run["worktree"]), run["base"])
        violations = outside_ownership(changes, policy["modules"][tasks[run["task_id"]]["module"]]["owned_paths"])
        if checkpoint_requested:
            checkpoint_path = _write_checkpoint(run, report, changes, checkpoint_reason,
                                                checkpoint_usage, followup_error)
        status = ("scope_violation" if violations else
                  "checkpointed" if checkpoint_requested else
                  "needs_review" if exit_code == 0 else "failed")
    except Exception as error:
        failure = f"{type(error).__name__}: {error}"
        if process and process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        changes, violations, status = [], [], "failed"
        if checkpoint_requested:
            try:
                policy, tasks = load_config()
                changes = changed_paths(Path(run["worktree"]), run["base"])
                violations = outside_ownership(changes, policy["modules"][tasks[run["task_id"]]["module"]]["owned_paths"])
                checkpoint_path = _write_checkpoint(run, "", changes, checkpoint_reason,
                                                    checkpoint_usage, failure)
                status = "scope_violation" if violations else "checkpointed"
            except Exception:
                pass
    finally:
        if reader and reader.is_alive():
            reader.join(timeout=5)
        if process:
            if process.stdin and not process.stdin.closed:
                try:
                    process.stdin.close()
                except BrokenPipeError:
                    pass
            if process.stdout and not process.stdout.closed:
                process.stdout.close()
    with state_lock() as state:
        state["runs"][run_id].update({"status": status, "exit_code": exit_code, "changed_paths": changes,
                                       "scope_violations": violations, "failure": failure,
                                       "checkpoint_path": str(checkpoint_path) if checkpoint_path else None,
                                       "finished_at": datetime.now(timezone.utc).isoformat()})
        state["tasks"][run["task_id"]]["status"] = status


def dispatch(task_id: str, provider: str | None, model: str | None = None,
             skip_usage_check: bool = False) -> dict[str, Any]:
    policy, tasks = load_config()
    if task_id not in tasks:
        raise OrchestratorError(f"Unknown task {task_id}")
    task = tasks[task_id]
    selected, reason, usage, errors = select_for_task(policy, task, provider, model, skip_usage_check)
    if not shutil.which("codex" if selected["provider"] == "ollama" else selected["provider"]):
        raise OrchestratorError(f"CLI for {selected['provider']} is not installed")
    with state_lock() as state:
        reasons = readiness(task, policy, state)
        if reasons:
            raise OrchestratorError("Task is blocked: " + "; ".join(reasons))
        base = git_ready()
        run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + uuid.uuid4().hex[:8]
        branch = f"agent/{task_id}-{run_id}"
        worktree = ROOT.parent / f".{ROOT.name}-worktrees" / run_id
        worktree.parent.mkdir(parents=True, exist_ok=True)
        git("worktree", "add", "-b", branch, str(worktree), base)
        run = {"run_id": run_id, "task_id": task_id, "module": task["module"], "provider": selected["provider"],
               "model": selected["model"], "effort": selected["effort"], "branch": branch,
               "base": base, "worktree": str(worktree), "prompt": build_prompt(task, policy),
               "status": "running", "started_at": datetime.now(timezone.utc).isoformat(),
               "selection_reason": reason, "usage_at_dispatch": usage, "usage_errors": errors}
        state["runs"][run_id] = run
        state["tasks"][task_id] = {"status": "running", "module": task["module"], "run_id": run_id}
        run_dir = RUNTIME / "runs" / run_id
        run_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        run_dir.chmod(0o700)
        (run_dir / "prompt.txt").write_text(run["prompt"], encoding="utf-8")
        snapshot = usage.get(selected["provider"])
        if snapshot and checkpoint_due(snapshot, policy, selected["model"]):
            save_checkpoint_request(run, "subscription limit already near threshold", snapshot)
    pid = launch_supervisor(run_id)
    return {"run_id": run_id, "task": task_id, "provider": selected["provider"], "model": selected["model"],
            "branch": branch, "worktree": str(worktree), "pid": pid,
            "selection_reason": reason, "usage": usage, "usage_errors": errors}


def launch_supervisor(run_id: str) -> int:
    with (RUNTIME / "runs" / run_id / "supervisor.log").open("w", encoding="utf-8") as supervisor:
        process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), "_execute", run_id],
                                   cwd=ROOT, stdout=supervisor, stderr=subprocess.STDOUT, start_new_session=True)
    with state_lock() as state:
        state["runs"][run_id]["pid"] = process.pid
    return process.pid


def request_checkpoint(task_id: str) -> dict[str, str]:
    with state_lock() as state:
        entry = state["tasks"].get(task_id, {})
        if entry.get("status") not in {"running", "checkpointing"}:
            raise OrchestratorError("Task is not running")
        run_id = entry["run_id"]
        run = dict(state["runs"][run_id], run_id=run_id)
    path = save_checkpoint_request(run, "manual checkpoint")
    return {"task": task_id, "run_id": run_id, "request": str(path)}


def resume(task_id: str, provider: str | None, model: str | None = None,
           skip_usage_check: bool = False, fresh_session: bool = False) -> dict[str, Any]:
    policy, tasks = load_config()
    if task_id not in tasks:
        raise OrchestratorError(f"Unknown task {task_id}")
    task = tasks[task_id]
    selected, reason, usage, errors = select_for_task(policy, task, provider, model, skip_usage_check)
    if not shutil.which("codex" if selected["provider"] == "ollama" else selected["provider"]):
        raise OrchestratorError(f"CLI for {selected['provider']} is not installed")
    with state_lock() as state:
        entry = state["tasks"].get(task_id, {})
        if entry.get("status") not in {"checkpointed", "failed"}:
            raise OrchestratorError("Task must be checkpointed or failed before resume")
        previous = dict(state["runs"][entry["run_id"]], run_id=entry["run_id"])
        worktree = Path(previous["worktree"])
        if not worktree.is_dir() or git("rev-parse", "--show-toplevel", cwd=worktree, check=False).returncode:
            raise OrchestratorError("Checkpoint worktree is missing")
        if git("rev-parse", "HEAD", cwd=worktree).stdout.decode().strip() != previous["base"]:
            raise OrchestratorError("Checkpoint worktree HEAD changed; review before resuming")
        artifact = RUNTIME / "tasks" / task_id / "checkpoint.md"
        if entry["status"] == "failed" or not artifact.is_file():
            changes = changed_paths(worktree, previous["base"])
            violations = outside_ownership(changes, policy["modules"][task["module"]]["owned_paths"])
            if violations:
                raise OrchestratorError("Review out-of-scope changes before resuming: " + ", ".join(violations))
            old_dir = RUNTIME / "runs" / entry["run_id"]
            summary = old_dir / "summary.txt"
            report = (summary.read_text(encoding="utf-8") if summary.is_file() else
                      _last_result_from_log(old_dir / "agent.log"))
            artifact = _write_checkpoint(previous, report, changes,
                                         "previous run failed or checkpoint artifact missing", None,
                                         previous.get("failure"))
        run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + uuid.uuid4().hex[:8]
        native_session = (previous.get("agent_session_id") if not fresh_session and
                          selected["provider"] == previous["provider"] else None)
        prompt = build_resume_prompt(task, policy, artifact.read_text(encoding="utf-8"))
        run = {"run_id": run_id, "task_id": task_id, "module": task["module"],
               "provider": selected["provider"], "model": selected["model"], "effort": selected["effort"],
               "branch": previous["branch"], "base": previous["base"], "worktree": str(worktree),
               "prompt": prompt, "status": "running", "started_at": datetime.now(timezone.utc).isoformat(),
               "selection_reason": reason, "usage_at_dispatch": usage, "usage_errors": errors,
               "resumed_from_run_id": entry["run_id"]}
        if native_session:
            run["resume_session_id"] = native_session
        request_path = checkpoint_request_path(worktree)
        request_path.unlink(missing_ok=True)
        state["runs"][run_id] = run
        state["tasks"][task_id] = {"status": "running", "module": task["module"], "run_id": run_id}
        run_dir = RUNTIME / "runs" / run_id
        run_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        run_dir.chmod(0o700)
        (run_dir / "prompt.txt").write_text(prompt, encoding="utf-8")
        snapshot = usage.get(selected["provider"])
        if snapshot and checkpoint_due(snapshot, policy, selected["model"]):
            save_checkpoint_request(run, "subscription limit already near threshold", snapshot)
    pid = launch_supervisor(run_id)
    return {"run_id": run_id, "task": task_id, "provider": selected["provider"],
            "model": selected["model"], "branch": run["branch"], "worktree": str(worktree),
            "pid": pid, "resumed_from_run_id": run["resumed_from_run_id"],
            "native_session_reused": bool(native_session), "selection_reason": reason,
            "usage": usage, "usage_errors": errors}


def accept(task_id: str) -> dict[str, str]:
    _, tasks = load_config()
    if task_id not in tasks:
        raise OrchestratorError(f"Unknown task {task_id}")
    with state_lock() as state:
        entry = state["tasks"].get(task_id, {})
        if entry.get("status") != "needs_review":
            raise OrchestratorError("Task must be in needs_review state")
        run = state["runs"][entry["run_id"]]
        if git("status", "--porcelain", "--untracked-files=all", cwd=Path(run["worktree"])).stdout:
            raise OrchestratorError("Commit the agent's changes before accepting")
        if git("merge-base", "--is-ancestor", run["branch"], "HEAD", check=False).returncode:
            raise OrchestratorError("Review and merge the agent branch into the current branch before accepting")
        missing = [path for path in tasks[task_id].get("required_outputs", []) if not (ROOT / path).is_file()]
        if missing:
            raise OrchestratorError("Required outputs are missing: " + ", ".join(missing))
        entry["status"] = "accepted"
        run["status"] = "accepted"
        run["accepted_at"] = datetime.now(timezone.utc).isoformat()
        return {"task": task_id, "status": "accepted", "branch": run["branch"]}


def status_rows(tasks: dict[str, dict[str, Any]], state: dict[str, Any]) -> list[dict[str, Any]]:
    rows = []
    for task in tasks.values():
        entry = state["tasks"].get(task["id"], {})
        row = {"task": task["id"], "status": entry.get("status", "queued")}
        if "run_id" in entry:
            run_id = entry["run_id"]
            run = state["runs"][run_id]
            row.update({"run_id": run_id, "provider": run["provider"], "model": run["model"],
                        "selection_reason": run.get("selection_reason"),
                        "usage_at_dispatch": run.get("usage_at_dispatch", {}),
                        "branch": run["branch"], "worktree": run["worktree"],
                        "log": str(RUNTIME / "runs" / run_id / "agent.log"),
                        "started_at": run["started_at"], "finished_at": run.get("finished_at"),
                        "changed_paths": run.get("changed_paths", []),
                        "agent_session_id": run.get("agent_session_id"),
                        "resumed_from_run_id": run.get("resumed_from_run_id"),
                        "checkpoint_path": run.get("checkpoint_path"),
                        "checkpoint_reason": run.get("checkpoint_reason"),
                        "usage_monitor_error": run.get("usage_monitor_error"),
                        "scope_violations": run.get("scope_violations", []),
                        "failure": run.get("failure")})
        rows.append(row)
    return rows


def summarize_event(line: str) -> str:
    try:
        event = json.loads(line)
    except json.JSONDecodeError:
        return line.rstrip()
    kind = event.get("type", "event")
    if kind == "system":
        subtype = event.get("subtype", "")
        if subtype in {"thinking_tokens", "hook_progress"}:
            return ""
        message = event.get("message") or event.get("error") or ""
        return f"system {subtype}: {str(message)[:1000]}" if message else f"system {subtype}"
    if kind == "user":
        blocks = (event.get("message") or {}).get("content") or []
        results = [str(block.get("content", ""))[:1000] for block in blocks
                   if isinstance(block, dict) and block.get("type") == "tool_result"]
        return "tool result: " + " | ".join(results) if results else ""
    item = event.get("item") or {}
    if item.get("type") == "agent_message":
        return str(item.get("text", ""))[:1000]
    if item.get("type") == "command_execution":
        return f"{kind}: {str(item.get('command', ''))[:500]}"
    if kind == "assistant":
        blocks = (event.get("message") or {}).get("content") or []
        parts = []
        for block in blocks:
            if block.get("type") == "text":
                parts.append(str(block.get("text", ""))[:1000])
            elif block.get("type") == "tool_use":
                parts.append(f"tool: {block.get('name', 'unknown')}")
        return " | ".join(parts) or kind
    if kind == "result":
        return f"result: {str(event.get('result', ''))[:1000]}"
    return kind


def show_logs(identifier: str, tasks: dict[str, dict[str, Any]], follow: bool, raw: bool, lines: int) -> None:
    with state_lock() as state:
        if identifier in tasks:
            run_id = state["tasks"].get(identifier, {}).get("run_id")
        else:
            run_id = identifier if identifier in state["runs"] else None
        if not run_id:
            raise OrchestratorError(f"No run found for {identifier}")
    log_path = RUNTIME / "runs" / run_id / "agent.log"
    while not log_path.exists():
        if not follow:
            raise OrchestratorError(f"Log is not available yet: {log_path}")
        with state_lock() as state:
            if state["runs"][run_id]["status"] not in {"running", "checkpointing"}:
                raise OrchestratorError(f"Log was not created: {log_path}")
        time.sleep(0.3)
    with log_path.open(encoding="utf-8", errors="replace") as stream:
        recent: deque[str] = deque(maxlen=lines)
        for line in stream:
            display = line.rstrip() if raw else summarize_event(line)
            if display:
                recent.append(display)
        for display in recent:
            print(display, flush=True)
        if not follow:
            return
        while True:
            line = stream.readline()
            if line:
                display = line.rstrip() if raw else summarize_event(line)
                if display:
                    print(display, flush=True)
                continue
            with state_lock() as state:
                if state["runs"][run_id]["status"] not in {"running", "checkpointing"}:
                    return
            time.sleep(0.3)


def main() -> int:
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)
    commands.add_parser("doctor", help="Show local prerequisites")
    commands.add_parser("list", help="List tasks, dependencies and routes")
    plan = commands.add_parser("plan", help="Show one task without starting an agent")
    plan.add_argument("task_id")
    plan.add_argument("--provider", choices=["codex", "claude", "ollama"])
    plan.add_argument("--model", help="Explicit model from policy.json allowed_models")
    plan.add_argument("--skip-usage-check", action="store_true", help="Use only with explicit provider/model")
    run = commands.add_parser("run", help="Start one task in an isolated worktree")
    run.add_argument("task_id")
    run.add_argument("--provider", choices=["codex", "claude", "ollama"])
    run.add_argument("--model", help="Explicit model from policy.json allowed_models")
    run.add_argument("--skip-usage-check", action="store_true", help="Use only with explicit provider/model")
    continued = commands.add_parser("resume", help="Continue a checkpointed task in its existing worktree")
    continued.add_argument("task_id")
    continued.add_argument("--provider", choices=["codex", "claude", "ollama"])
    continued.add_argument("--model", help="Explicit model from policy.json allowed_models")
    continued.add_argument("--skip-usage-check", action="store_true", help="Use only with explicit provider/model")
    continued.add_argument("--fresh-session", action="store_true",
                           help="Use checkpoint files without reusing the provider's native session")
    checkpoint = commands.add_parser("checkpoint", help="Ask a running task to stop safely and save progress")
    checkpoint.add_argument("task_id")
    commands.add_parser("usage", help="Read current subscription usage from both CLIs")
    commands.add_parser("status", help="Show dispatched task states")
    logs = commands.add_parser("logs", help="Show an agent's recent events")
    logs.add_argument("task_or_run_id")
    logs.add_argument("--follow", action="store_true", help="Continue until the run finishes")
    logs.add_argument("--raw", action="store_true", help="Print complete JSONL events")
    logs.add_argument("--lines", type=int, default=50, help="Number of recent events (default: 50)")
    accepted = commands.add_parser("accept", help="Mark a reviewed and merged task complete")
    accepted.add_argument("task_id")
    internal = commands.add_parser("_execute", help=argparse.SUPPRESS)
    internal.add_argument("run_id")
    args = parser.parse_args()
    try:
        policy, tasks = load_config()
        if args.command == "_execute":
            execute(args.run_id)
            return 0
        if args.command == "doctor":
            info = {"root": str(ROOT), "git": git("rev-parse", "--is-inside-work-tree", check=False).returncode == 0,
                    "head": git("rev-parse", "--short", "HEAD", check=False).stdout.decode().strip(),
                    "codex": shutil.which("codex"), "claude": shutil.which("claude"), "ollama": shutil.which("ollama"),
                    "ollama_enabled": policy["ollama"]["enabled"]}
            print(json.dumps(info, ensure_ascii=False, indent=2))
        elif args.command == "run":
            print(json.dumps(dispatch(args.task_id, args.provider, args.model, args.skip_usage_check),
                             ensure_ascii=False, indent=2))
        elif args.command == "resume":
            print(json.dumps(resume(args.task_id, args.provider, args.model,
                                    args.skip_usage_check, args.fresh_session),
                             ensure_ascii=False, indent=2))
        elif args.command == "checkpoint":
            print(json.dumps(request_checkpoint(args.task_id), ensure_ascii=False, indent=2))
        elif args.command == "usage":
            snapshots, errors = read_usage(policy["providers"])
            print(json.dumps({"usage": snapshots, "errors": errors}, ensure_ascii=False, indent=2))
            if not snapshots:
                return 2
        elif args.command == "accept":
            print(json.dumps(accept(args.task_id), ensure_ascii=False, indent=2))
        elif args.command == "logs":
            if args.lines < 1:
                raise OrchestratorError("--lines must be positive")
            show_logs(args.task_or_run_id, tasks, args.follow, args.raw, args.lines)
        elif args.command == "plan":
            if args.task_id not in tasks:
                raise OrchestratorError(f"Unknown task {args.task_id}")
            task = tasks[args.task_id]
            selected, reason, usage, errors = select_for_task(
                policy, task, args.provider, args.model, args.skip_usage_check)
            with state_lock() as state:
                blockers = readiness(task, policy, state)
            print(json.dumps({"task": task, "route": selected, "selection_reason": reason,
                              "usage": usage, "usage_errors": errors,
                              "brief": policy["modules"][task["module"]]["brief"],
                              "owned_paths": policy["modules"][task["module"]]["owned_paths"],
                              "blockers": blockers}, ensure_ascii=False, indent=2))
        else:
            with state_lock() as state:
                if args.command == "status":
                    print(json.dumps(status_rows(tasks, state), ensure_ascii=False, indent=2))
                elif args.command == "list":
                    rows = []
                    for task in tasks.values():
                        selected = route(policy, task)
                        rows.append({"id": task["id"], "module": task["module"], "kind": task["kind"],
                                     "status": task_state(state, task["id"]), "blockers": readiness(task, policy, state),
                                     "provider": selected["provider"], "model": selected["model"]})
                    print(json.dumps(rows, ensure_ascii=False, indent=2))
        return 0
    except (OrchestratorError, KeyError, ValueError) as error:
        print(f"orchestrator: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
