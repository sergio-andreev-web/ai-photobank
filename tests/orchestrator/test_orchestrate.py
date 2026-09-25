import importlib.util
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("orchestrate", ROOT / "scripts" / "orchestrate.py")
orchestrate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(orchestrate)


class OrchestratorTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.policy, cls.tasks = orchestrate.load_config()

    def test_route_uses_task_risk_not_whole_module(self):
        expected = {
            "backend-schema-base": ("codex", "gpt-6-luna"),
            "backend-schema-domain": ("claude", "claude-sonnet-5"),
            "backend-processing": ("claude", "claude-opus-5-5"),
            "backend-taxonomy": ("claude", "claude-opus-5-5"),
            "backend-search": ("claude", "claude-opus-5-5"),
            "contract-fixtures": ("claude", "claude-haiku-4-5"),
        }
        for task_id, target in expected.items():
            with self.subTest(task=task_id):
                selected = orchestrate.route(self.policy, self.tasks[task_id])
                self.assertEqual((selected["provider"], selected["model"]), target)
        override = orchestrate.route(self.policy, self.tasks["backend-taxonomy"], "claude")
        self.assertEqual(override["model"], "claude-opus-5-5")
        override = orchestrate.route(self.policy, self.tasks["backend-taxonomy"], "codex")
        self.assertEqual(override["model"], "gpt-6-sol")

    def test_explicit_model_and_codex_ceiling(self):
        task = self.tasks["backend-processing"]
        self.assertEqual(orchestrate.route(self.policy, task, model="gpt-6-sol")["provider"], "codex")
        self.assertEqual(orchestrate.route(self.policy, task, "claude", "claude-fable-5-1")["model"],
                         "claude-fable-5-1")
        self.assertEqual(orchestrate.route(self.policy, task, model="claude-haiku-4-5")["effort"], "")
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.route(self.policy, task, "codex", "gpt-6-ultra")
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.route(self.policy, task, "claude", "gpt-6-sol")

    def test_usage_parsers_use_five_hour_window_for_routing(self):
        codex = orchestrate.parse_codex_usage(
            {"ordinaryUsageAllowed": True, "rateLimits": {
                "primary": {"usedPercent": 80}, "secondary": {"usedPercent": 10}}})
        self.assertEqual(codex["remaining_percent"], 20)
        self.assertEqual(codex["weekly_remaining_percent"], 90)
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.parse_codex_usage(
                {"ordinaryUsageAllowed": True, "rateLimits": {"primary": {"usedPercent": 80}}})
        claude = orchestrate.parse_claude_usage(
            {"local_command": "usage", "num_turns": 0, "is_error": False,
             "result": "Current session: 20% used\nCurrent week (all models): 60% used\n"
                       "Current week (Fable): 10% used"})
        self.assertEqual(claude["remaining_percent"], 80)
        self.assertEqual(claude["weekly_remaining_percent"], 40)
        self.assertEqual(claude["fable_remaining_percent"], 90)
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.parse_claude_usage({"local_command": "usage", "num_turns": 1, "result": ""})

    def test_auto_routing_uses_verified_balance(self):
        task = self.tasks["backend-media-api"]  # policy prefers Codex for this task
        usage = {"codex": {"remaining_percent": 20, "weekly_remaining_percent": 90},
                 "claude": {"remaining_percent": 80, "weekly_remaining_percent": 40}}
        selected, reason = orchestrate.choose_route(self.policy, task, None, None, usage, {})
        self.assertEqual((selected["provider"], selected["model"]), ("claude", "claude-sonnet-5"))
        self.assertIn("80%", reason)
        usage["codex"]["remaining_percent"] = 75
        selected, _ = orchestrate.choose_route(self.policy, task, None, None, usage, {})
        self.assertEqual(selected["provider"], "codex")
        usage["codex"].update(remaining_percent=80, weekly_remaining_percent=10)
        usage["claude"].update(remaining_percent=50, weekly_remaining_percent=90)
        selected, _ = orchestrate.choose_route(self.policy, task, None, None, usage, {})
        self.assertEqual(selected["provider"], "codex")
        selected, _ = orchestrate.choose_route(
            self.policy, task, None, None,
            {"claude": {"remaining_percent": 40, "weekly_remaining_percent": 40}},
            {"codex": "usage probe failed"})
        self.assertEqual(selected["provider"], "claude")
        usage["codex"]["remaining_percent"] = 2
        usage["claude"]["remaining_percent"] = 4
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.choose_route(self.policy, task, None, None, usage, {})
        selected, _ = orchestrate.choose_route(self.policy, task, "claude", "claude-opus-5-5",
                                                {"claude": {"remaining_percent": 4,
                                                            "weekly_remaining_percent": 40}}, {})
        self.assertEqual(selected["model"], "claude-opus-5-5")
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.choose_route(self.policy, task, "claude", "claude-fable-5-1",
                                     {"claude": {"remaining_percent": 40,
                                                "weekly_remaining_percent": 40,
                                                "fable_remaining_percent": 0}}, {})
        usage["codex"]["remaining_percent"] = 90
        usage["codex"]["weekly_remaining_percent"] = 0
        usage["claude"]["remaining_percent"] = 30
        selected, _ = orchestrate.choose_route(self.policy, task, None, None, usage, {})
        self.assertEqual(selected["provider"], "claude")
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.choose_route(self.policy, task, "codex", None, {},
                                     {"codex": "usage probe failed"})

    def test_all_configured_codex_models_respect_ceiling(self):
        self.assertEqual(set(self.policy["allowed_models"]["codex"]), {"gpt-6-luna", "gpt-6-sol"})
        for task in self.tasks.values():
            with self.subTest(task=task["id"]):
                self.assertIn(orchestrate.route(self.policy, task, "codex")["model"],
                              self.policy["allowed_models"]["codex"])

    def test_skip_usage_check_requires_explicit_selection(self):
        task = self.tasks["backend-processing"]
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.select_for_task(self.policy, task, None, None, True)
        selected, reason, snapshots, errors = orchestrate.select_for_task(
            self.policy, task, "codex", "gpt-6-sol", True)
        self.assertEqual(selected["model"], "gpt-6-sol")
        self.assertIn("skipped", reason)
        self.assertEqual((snapshots, errors), ({}, {}))

    def test_ollama_requires_explicit_opt_in(self):
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.route(self.policy, {"kind": "migration_simple", "module": "backend"}, "ollama")

    def test_dependencies_block_until_accepted(self):
        empty = {"tasks": {}, "runs": {}}
        self.assertEqual(orchestrate.readiness(self.tasks["contracts-v1"], self.policy, empty), [])
        self.assertIn("dependency contracts-v1 is queued",
                      orchestrate.readiness(self.tasks["contract-fixtures"], self.policy, empty))
        accepted = {"tasks": {"contracts-v1": {"status": "accepted", "module": "contracts"}}, "runs": {}}
        self.assertEqual(orchestrate.readiness(self.tasks["contract-fixtures"], self.policy, accepted), [])

    def test_same_owned_paths_cannot_run_parallel(self):
        state = {"tasks": {"backend-schema-base": {"status": "needs_review", "module": "backend"}}, "runs": {}}
        blockers = orchestrate.readiness(self.tasks["backend-schema-domain"], self.policy, state)
        self.assertIn("owned paths occupied by backend-schema-base", blockers)

    def test_scope_check_is_path_specific(self):
        changed = ["apps/api/database/migrations/a.php", "apps/admin/pages/index.vue", "apps/api-bad/file"]
        self.assertEqual(orchestrate.outside_ownership(changed, ["apps/api/"]), changed[1:])

    def test_command_passes_selected_model(self):
        run = {"provider": "codex", "model": "gpt-6-luna", "effort": "medium",
               "worktree": "/tmp/photobank-task", "prompt": "test"}
        command = orchestrate.agent_command(run, Path("/tmp/summary.txt"))
        self.assertEqual(command[command.index("--model") + 1], "gpt-6-luna")
        self.assertIn("workspace-write", command)
        run.update(provider="claude", model="claude-sonnet-5")
        command = orchestrate.agent_command(run, Path("/tmp/summary.txt"))
        self.assertEqual(command[command.index("--model") + 1], "claude-sonnet-5")
        self.assertIn("--permission-prompts", command)
        self.assertEqual(command[command.index("--output-format") + 1], "stream-json")
        run.update(model="claude-haiku-4-5", effort="")
        command = orchestrate.agent_command(run, Path("/tmp/summary.txt"))
        self.assertNotIn("--effort", command)

    def test_status_and_log_summary(self):
        state = {"tasks": {"contracts-v1": {"status": "running", "run_id": "run1"}},
                 "runs": {"run1": {"provider": "codex", "model": "gpt-6-sol", "branch": "agent/test",
                                   "worktree": "/tmp/task", "started_at": "now"}}}
        row = orchestrate.status_rows(self.tasks, state)[0]
        self.assertEqual((row["task"], row["status"], row["model"]),
                         ("contracts-v1", "running", "gpt-6-sol"))
        self.assertIn("hello", orchestrate.summarize_event('{"type":"item.completed","item":{"type":"agent_message","text":"hello"}}'))


if __name__ == "__main__":
    unittest.main()
