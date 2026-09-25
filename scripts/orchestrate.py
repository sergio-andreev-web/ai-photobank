#!/usr/bin/env python3
"""Small, deterministic dispatcher for this repository's coding agents."""

from __future__ import annotations

import argparse
import fcntl
import json
import os
import re
import shutil
import subprocess
import sys
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


def route(policy: dict[str, Any], task: dict[str, Any], provider: str | None = None) -> dict[str, str]:
    rule = policy["routes"][task["kind"]]
    selected = provider or rule["default_provider"]
    if selected == "ollama":
        local = policy["ollama"]
        if not local["enabled"] or not local["model"]:
            raise OrchestratorError("Ollama is disabled; set enabled and model in policy.json")
        if task["kind"] not in local["allowed_kinds"]:
            raise OrchestratorError(f"Ollama is not allowed for kind {task['kind']}")
        return {"provider": "ollama", "model": local["model"], "effort": ""}
    if selected not in policy["providers"] or selected not in rule:
        raise OrchestratorError(f"Provider {selected} cannot run {task['kind']}")
    return {"provider": selected, **rule[selected]}


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
        if other_id == task["id"] or record.get("status") not in {"running", "needs_review", "scope_violation"}:
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
"""


def agent_command(run: dict[str, Any], summary: Path) -> list[str]:
    provider, model, effort = run["provider"], run["model"], run["effort"]
    worktree = run["worktree"]
    if provider in {"codex", "ollama"}:
        command = ["codex", "exec", "--model", model, "--sandbox", "workspace-write", "--cd", worktree,
                   "--json", "--output-last-message", str(summary)]
        if provider == "ollama":
            command += ["--oss", "--local-provider", "ollama"]
        else:
            command += ["--config", f'model_reasoning_effort="{effort}"']
        return command + ["-"]
    if provider == "claude":
        return ["claude", "--print", "--model", model, "--effort", effort,
                "--output-format", "stream-json", "--permission-mode", "acceptEdits",
                "--permission-prompts", "none", run["prompt"]]
    raise OrchestratorError(f"Unsupported provider {provider}")


def execute(run_id: str) -> None:
    with state_lock() as state:
        run = dict(state["runs"][run_id])
    run_dir = RUNTIME / "runs" / run_id
    run_dir.mkdir(parents=True, exist_ok=True)
    summary = run_dir / "summary.txt"
    command = agent_command(run, summary)
    log_path = run_dir / "agent.log"
    exit_code = 1
    failure = None
    try:
        with log_path.open("w", encoding="utf-8") as log:
            result = subprocess.run(command, cwd=run["worktree"], input=run["prompt"] if run["provider"] != "claude" else None,
                                    text=True, stdout=log, stderr=subprocess.STDOUT, check=False)
        exit_code = result.returncode
        policy, tasks = load_config()
        changes = changed_paths(Path(run["worktree"]), run["base"])
        violations = outside_ownership(changes, policy["modules"][tasks[run["task_id"]]["module"]]["owned_paths"])
        status = "scope_violation" if violations else ("needs_review" if exit_code == 0 else "failed")
    except Exception as error:
        failure = f"{type(error).__name__}: {error}"
        changes, violations, status = [], [], "failed"
    with state_lock() as state:
        state["runs"][run_id].update({"status": status, "exit_code": exit_code, "changed_paths": changes,
                                       "scope_violations": violations, "failure": failure,
                                       "finished_at": datetime.now(timezone.utc).isoformat()})
        state["tasks"][run["task_id"]]["status"] = status


def dispatch(task_id: str, provider: str | None) -> dict[str, Any]:
    policy, tasks = load_config()
    if task_id not in tasks:
        raise OrchestratorError(f"Unknown task {task_id}")
    task = tasks[task_id]
    selected = route(policy, task, provider)
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
        run = {"task_id": task_id, "module": task["module"], "provider": selected["provider"],
               "model": selected["model"], "effort": selected["effort"], "branch": branch,
               "base": base, "worktree": str(worktree), "prompt": build_prompt(task, policy),
               "status": "running", "started_at": datetime.now(timezone.utc).isoformat()}
        state["runs"][run_id] = run
        state["tasks"][task_id] = {"status": "running", "module": task["module"], "run_id": run_id}
        run_dir = RUNTIME / "runs" / run_id
        run_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        run_dir.chmod(0o700)
        (run_dir / "prompt.txt").write_text(run["prompt"], encoding="utf-8")
    with (RUNTIME / "runs" / run_id / "supervisor.log").open("w", encoding="utf-8") as supervisor:
        process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), "_execute", run_id],
                                   cwd=ROOT, stdout=supervisor, stderr=subprocess.STDOUT, start_new_session=True)
    with state_lock() as state:
        state["runs"][run_id]["pid"] = process.pid
    return {"run_id": run_id, "task": task_id, "provider": selected["provider"], "model": selected["model"],
            "branch": branch, "worktree": str(worktree), "pid": process.pid}


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
                        "branch": run["branch"], "worktree": run["worktree"],
                        "log": str(RUNTIME / "runs" / run_id / "agent.log"),
                        "started_at": run["started_at"], "finished_at": run.get("finished_at"),
                        "changed_paths": run.get("changed_paths", []),
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
            if state["runs"][run_id]["status"] != "running":
                raise OrchestratorError(f"Log was not created: {log_path}")
        time.sleep(0.3)
    with log_path.open(encoding="utf-8", errors="replace") as stream:
        for line in deque(stream, maxlen=lines):
            print(line.rstrip() if raw else summarize_event(line), flush=True)
        if not follow:
            return
        while True:
            line = stream.readline()
            if line:
                print(line.rstrip() if raw else summarize_event(line), flush=True)
                continue
            with state_lock() as state:
                if state["runs"][run_id]["status"] != "running":
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
    run = commands.add_parser("run", help="Start one task in an isolated worktree")
    run.add_argument("task_id")
    run.add_argument("--provider", choices=["codex", "claude", "ollama"])
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
            print(json.dumps(dispatch(args.task_id, args.provider), ensure_ascii=False, indent=2))
        elif args.command == "accept":
            print(json.dumps(accept(args.task_id), ensure_ascii=False, indent=2))
        elif args.command == "logs":
            if args.lines < 1:
                raise OrchestratorError("--lines must be positive")
            show_logs(args.task_or_run_id, tasks, args.follow, args.raw, args.lines)
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
                elif args.command == "plan":
                    if args.task_id not in tasks:
                        raise OrchestratorError(f"Unknown task {args.task_id}")
                    task = tasks[args.task_id]
                    print(json.dumps({"task": task, "route": route(policy, task, args.provider),
                                      "brief": policy["modules"][task["module"]]["brief"],
                                      "owned_paths": policy["modules"][task["module"]]["owned_paths"],
                                      "blockers": readiness(task, policy, state)}, ensure_ascii=False, indent=2))
        return 0
    except (OrchestratorError, KeyError, ValueError) as error:
        print(f"orchestrator: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
