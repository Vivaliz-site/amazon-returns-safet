from datetime import datetime, timezone
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from tests.continuity.git_fixture import GitFixture
from tools.continuity.dispatcher import AgentCandidate, Dispatcher, _default_launcher
from tools.continuity.ledger import Ledger
from tools.continuity.model import Classification, TaskRecord, TaskStatus

UTC = timezone.utc


class DispatcherTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.ledger = Ledger(Path(self.tmp.name) / "ledger.sqlite3")
        self.now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)

    def add_task(self):
        task = TaskRecord(
            task_id="TASK-20260913-007", repository="org/repo", host="host",
            worktree_path=str(self.fx.repo), branch="main",
            base_sha=self.fx.git("rev-parse", "origin/main").stdout.strip(),
            current_head=self.fx.git("rev-parse", "HEAD").stdout.strip(),
            objective="resume safely", status=TaskStatus.NEEDS_RESUME,
            classification=Classification.NEEDS_RESUME, priority=10,
            next_action="continue", created_at=self.now, updated_at=self.now,
        )
        self.ledger.create_task(task)
        return task

    def candidates(self):
        return [
            AgentCandidate("gemini", ["gemini", "--resume"], True),
            AgentCandidate("claude", ["claude", "--resume"], True),
            AgentCandidate("chatgpt", ["chatgpt", "work"], True),
            AgentCandidate("codex", ["codex", "exec"], True),
        ]

    def test_select_agent_uses_required_fallback_order(self):
        dispatcher = Dispatcher(self.ledger, self.candidates(), auto_dispatch=False)
        for availability, expected in (
            ({"codex": True, "chatgpt": True, "claude": True, "gemini": True}, "codex"),
            ({"codex": False, "chatgpt": True, "claude": True, "gemini": True}, "chatgpt"),
            ({"codex": False, "chatgpt": False, "claude": True, "gemini": True}, "claude"),
            ({"codex": False, "chatgpt": False, "claude": False, "gemini": True}, "gemini"),
        ):
            self.assertEqual(expected, dispatcher.select_agent(self.candidates(), availability).name)

    def test_preview_mode_produces_packet_without_claim_or_launch(self):
        task = self.add_task()
        launches = []
        dispatcher = Dispatcher(
            self.ledger, self.candidates(), auto_dispatch=False,
            availability={"codex": True}, launcher=lambda candidate, packet, cwd: launches.append(candidate.name) or True,
        )
        decision = dispatcher.claim_next(self.now)
        self.assertEqual("codex", decision.agent.name)
        self.assertIn(task.task_id, decision.resume_packet)
        self.assertFalse(decision.claimed)
        self.assertFalse(decision.launched)
        self.assertEqual([], launches)
        self.assertIsNone(self.ledger.get_task(task.task_id).lease_expires_at)

    def test_active_lease_prevents_second_dispatcher_claim(self):
        task = self.add_task()
        self.ledger.claim(task.task_id, "codex", "session-a", self.now, 1800)
        dispatcher = Dispatcher(self.ledger, self.candidates(), auto_dispatch=True, availability={"codex": True})
        self.assertIsNone(dispatcher.claim_next(self.now))

    def test_launcher_receives_existing_task_worktree(self):
        task = self.add_task()
        seen = []
        def launcher(candidate, packet, cwd):
            seen.append(Path(cwd).resolve())
            return True
        dispatcher = Dispatcher(
            self.ledger, self.candidates(), auto_dispatch=True,
            availability={"codex": True}, launcher=launcher,
        )
        decision = dispatcher.claim_next(self.now)
        self.assertTrue(decision.launched)
        self.assertEqual([Path(task.worktree_path).resolve()], seen)
        loaded = self.ledger.get_task(task.task_id)
        self.assertIsNone(loaded.lease_expires_at)
        self.assertEqual(Classification.NEEDS_RESUME, loaded.classification)

    def test_default_launcher_does_not_stream_agent_output_into_controller_logs(self):
        candidate = AgentCandidate("codex", ["codex", "exec"], True)
        completed = subprocess.CompletedProcess(candidate.command, 0)
        with patch("tools.continuity.dispatcher.subprocess.run", return_value=completed) as run:
            self.assertTrue(_default_launcher(candidate, "resume packet", self.fx.repo))
        kwargs = run.call_args.kwargs
        self.assertIs(subprocess.DEVNULL, kwargs["stdout"])
        self.assertIs(subprocess.DEVNULL, kwargs["stderr"])

    def test_launch_failure_advances_to_next_available_agent(self):
        self.add_task()
        launches = []
        def launcher(candidate, packet, cwd):
            launches.append(candidate.name)
            return candidate.name == "chatgpt"
        dispatcher = Dispatcher(
            self.ledger, self.candidates(), auto_dispatch=True,
            availability={"codex": True, "chatgpt": True}, launcher=launcher,
        )
        first = dispatcher.claim_next(self.now)
        second = dispatcher.claim_next(self.now)
        self.assertEqual("codex", first.agent.name)
        self.assertFalse(first.launched)
        self.assertEqual("chatgpt", second.agent.name)
        self.assertTrue(second.launched)
        self.assertEqual(["codex", "chatgpt"], launches)

    def test_launch_failure_persists_attempt_then_requeues_same_worktree_and_branch(self):
        task = self.add_task()
        dispatcher = Dispatcher(
            self.ledger, self.candidates(), auto_dispatch=True,
            availability={"codex": True}, launcher=lambda candidate, packet, cwd: False,
        )
        decision = dispatcher.claim_next(self.now)
        self.assertTrue(decision.claimed)
        self.assertFalse(decision.launched)
        loaded = self.ledger.get_task(task.task_id)
        self.assertEqual(Classification.NEEDS_RESUME, loaded.classification)
        self.assertEqual(TaskStatus.NEEDS_RESUME, loaded.status)
        self.assertEqual(task.worktree_path, loaded.worktree_path)
        self.assertEqual(task.branch, loaded.branch)
        self.assertIsNone(loaded.lease_expires_at)
        attempts = self.ledger.list_dispatch_attempts(task.task_id)
        self.assertEqual("launch_failed", attempts[-1]["outcome"])
        self.assertEqual("codex", attempts[-1]["agent_type"])


if __name__ == "__main__":
    unittest.main()
