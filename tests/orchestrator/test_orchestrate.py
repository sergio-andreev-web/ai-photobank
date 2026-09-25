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
        migration = orchestrate.route(self.policy, {"kind": "migration", "module": "backend"})
        backend = orchestrate.route(self.policy, self.tasks["backend-core"])
        self.assertEqual((migration["provider"], migration["model"]), ("codex", "gpt-6-luna"))
        self.assertEqual((backend["provider"], backend["model"]), ("claude", "claude-opus-5-5"))

    def test_ollama_requires_explicit_opt_in(self):
        with self.assertRaises(orchestrate.OrchestratorError):
            orchestrate.route(self.policy, {"kind": "migration", "module": "backend"}, "ollama")

    def test_dependencies_block_until_accepted(self):
        empty = {"tasks": {}, "runs": {}}
        self.assertEqual(orchestrate.readiness(self.tasks["contracts-v1"], self.policy, empty), [])
        self.assertIn("dependency contracts-v1 is queued",
                      orchestrate.readiness(self.tasks["backend-migrations"], self.policy, empty))
        accepted = {"tasks": {"contracts-v1": {"status": "accepted", "module": "contracts"}}, "runs": {}}
        self.assertEqual(orchestrate.readiness(self.tasks["backend-migrations"], self.policy, accepted), [])

    def test_same_owned_paths_cannot_run_parallel(self):
        state = {"tasks": {"backend-migrations": {"status": "needs_review", "module": "backend"}}, "runs": {}}
        blockers = orchestrate.readiness(self.tasks["backend-core"], self.policy, state)
        self.assertIn("owned paths occupied by backend-migrations", blockers)

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
