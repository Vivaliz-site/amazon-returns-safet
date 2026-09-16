from datetime import datetime, timezone
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

from tests.continuity.git_fixture import GitFixture
from tools.continuity.dispatcher import AgentCandidate, Dispatcher, _default_launcher
from tools.continuity.job_queue import QueuePaths, load_job
from tools.continuity.ledger import Ledger
from tools.continuity.model import Classification, TaskRecord, TaskStatus
from tools.continuity.worktrees import create_worker_task_worktree, prepare_worker_source

UTC = timezone.utc


class DispatcherTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.ledger = Ledger(self.root / "ledger.sqlite3")
        self.paths = QueuePaths.under(self.root / "jobs")
        self.worker_base = self.root / "worker"
        source = prepare_worker_source(str(self.fx.remote), self.root / "sources", "org/repo")
        self.worker = create_worker_task_worktree(
            source, self.worker_base, "TASK-20260913-007", "continuity", "origin/main", os.getuid()
        )
        self.now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)

    def add_task(self, *, worker_owned=False):
        path = self.worker.path if worker_owned else self.fx.repo
        branch = self.worker.branch if worker_owned else "main"
        head = subprocess.run(["git", "-C", str(path), "rev-parse", "HEAD"], check=True, text=True, capture_output=True).stdout.strip()
        task = TaskRecord(
            task_id="TASK-20260913-007", repository="org/repo", host="host",
            worktree_path=str(path), branch=branch,
            base_sha=self.fx.git("rev-parse", "origin/main").stdout.strip(),
            current_head=head, objective="resume safely",
            status=TaskStatus.NEEDS_RESUME, classification=Classification.NEEDS_RESUME,
            priority=10, next_action="continue", created_at=self.now, updated_at=self.now,
        )
        self.ledger.create_task(task)
        return task

    def candidates(self):
        return [
            AgentCandidate("gemini", ["gemini", "--resume"], True),
            AgentCandidate("claude", ["claude", "--resume"], True),
            AgentCandidate("chatgpt", ["chatgpt", "work"], True),
            AgentCandidate("codex", ["codex", "exec"], True),
            AgentCandidate("rooter", ["rooter", "resume"], True),
        ]

    def dispatcher(self, *, auto_dispatch, availability, launcher=None, max_auto_attempts=3):
        return Dispatcher(
            self.ledger, self.candidates(), auto_dispatch=auto_dispatch,
            availability=availability, launcher=launcher,
            queue_paths=self.paths, worker_root=self.worker_base / "worktrees",
            worker_uid=os.getuid(), max_auto_attempts=max_auto_attempts,
        )

    def test_auto_dispatch_queues_gemini_job_without_launcher(self):
        task = self.add_task(worker_owned=True)
        dispatcher = self.dispatcher(
            auto_dispatch=True, availability={"gemini": True},
            launcher=lambda *args: self.fail("automatic dispatch must not invoke launcher"),
        )
        decision = dispatcher.claim_next(self.now)
        self.assertTrue(decision.queued)
        self.assertFalse(decision.launched)
        self.assertTrue(decision.claimed)
        self.assertEqual("gemini", decision.agent.name)
        job_path = self.paths.pending / f"{decision.job_id}.json"
        self.assertTrue(job_path.exists())
        job = load_job(job_path)
        self.assertEqual("gemini", job.provider)
        packet_path = Path(job.resume_packet_path)
        self.assertTrue(packet_path.exists())
        self.assertEqual(job.resume_packet_sha256, hashlib.sha256(packet_path.read_bytes()).hexdigest())
        loaded = self.ledger.get_task(task.task_id)
        self.assertEqual(Classification.ACTIVE, loaded.classification)

    def test_auto_dispatch_rejects_legacy_shared_worktree(self):
        task = self.add_task(worker_owned=False)
        decision = self.dispatcher(auto_dispatch=True, availability={"gemini": True}).claim_next(self.now)
        self.assertIsNone(decision)
        self.assertIsNone(self.ledger.get_task(task.task_id).lease_expires_at)

    def test_auto_dispatch_routes_to_rooter_only_when_gemini_unavailable(self):
        self.add_task(worker_owned=True)
        decision = self.dispatcher(
            auto_dispatch=True,
            availability={"codex": True, "chatgpt": True, "claude": True, "gemini": False, "rooter": True},
        ).claim_next(self.now)
        self.assertEqual("rooter", decision.agent.name)
        self.assertTrue(decision.queued)
        job = load_job(self.paths.pending / f"{decision.job_id}.json")
        self.assertEqual("rooter", job.provider)

    def test_select_agent_uses_interactive_fallback_order_in_preview_mode(self):
        dispatcher = self.dispatcher(auto_dispatch=False, availability={})
        for availability, expected in (
            ({"codex": True, "chatgpt": True, "claude": True, "gemini": True}, "codex"),
            ({"codex": False, "chatgpt": True, "claude": True, "gemini": True}, "chatgpt"),
            ({"codex": False, "chatgpt": False, "claude": True, "gemini": True}, "claude"),
            ({"codex": False, "chatgpt": False, "claude": False, "gemini": True}, "gemini"),
        ):
            self.assertEqual(expected, dispatcher.select_agent(self.candidates(), availability).name)

    def test_preview_mode_produces_packet_without_claim_or_queue(self):
        task = self.add_task(worker_owned=False)
        decision = self.dispatcher(auto_dispatch=False, availability={"codex": True}).claim_next(self.now)
        self.assertEqual("codex", decision.agent.name)
        self.assertIn(task.task_id, decision.resume_packet)
        self.assertFalse(decision.claimed)
        self.assertFalse(decision.launched)
        self.assertFalse(decision.queued)
        self.assertIsNone(decision.job_id)
        self.assertIsNone(self.ledger.get_task(task.task_id).lease_expires_at)

    def test_active_lease_prevents_second_dispatcher_claim(self):
        task = self.add_task(worker_owned=True)
        self.ledger.claim(task.task_id, "gemini", "session-a", self.now, 1800)
        decision = self.dispatcher(auto_dispatch=True, availability={"gemini": True}).claim_next(self.now)
        self.assertIsNone(decision)

    def test_automatic_mode_never_selects_interactive_agents(self):
        self.add_task(worker_owned=True)
        decision = self.dispatcher(
            auto_dispatch=True,
            availability={"codex": True, "chatgpt": True, "claude": True, "gemini": False, "rooter": False},
        ).claim_next(self.now)
        self.assertIsNone(decision)

    def test_retry_budget_blocks_further_automatic_queueing(self):
        task = self.add_task(worker_owned=True)
        for index in range(3):
            self.ledger.record_dispatch_attempt(task.task_id, "gemini", self.now, "dispatch_queued", f"job-{index}")
        decision = self.dispatcher(
            auto_dispatch=True, availability={"gemini": True}, max_auto_attempts=3
        ).claim_next(self.now)
        self.assertIsNone(decision)
        loaded = self.ledger.get_task(task.task_id)
        self.assertEqual(Classification.BLOCKED_EXTERNAL, loaded.classification)
        self.assertEqual(TaskStatus.BLOCKED, loaded.status)
        self.assertEqual("automatic retry budget exhausted", loaded.blocker)

    def test_recent_provider_failure_respects_cooldown(self):
        task = self.add_task(worker_owned=True)
        self.ledger.record_dispatch_attempt(task.task_id, "gemini", self.now, "launch_failed", "provider failed")
        decision = self.dispatcher(auto_dispatch=True, availability={"gemini": True}).claim_next(self.now)
        self.assertIsNone(decision)

    def test_default_launcher_does_not_stream_agent_output_into_controller_logs(self):
        candidate = AgentCandidate("codex", ["codex", "exec"], True)
        completed = subprocess.CompletedProcess(candidate.command, 0)
        with patch("tools.continuity.dispatcher.subprocess.run", return_value=completed) as run:
            self.assertTrue(_default_launcher(candidate, "resume packet", self.fx.repo))
        kwargs = run.call_args.kwargs
        self.assertIs(subprocess.DEVNULL, kwargs["stdout"])
        self.assertIs(subprocess.DEVNULL, kwargs["stderr"])


if __name__ == "__main__":
    unittest.main()
