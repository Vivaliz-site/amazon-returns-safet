from datetime import datetime, timedelta, timezone
from pathlib import Path
import tempfile
import unittest

from tools.continuity.ledger import Ledger, LeaseConflict
from tools.continuity.model import Classification, TaskRecord, TaskStatus

UTC = timezone.utc


class LedgerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.ledger = Ledger(Path(self.tmp.name) / "ledger.sqlite3")

    def task(self, task_id="TASK-20260913-001", priority=20):
        now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)
        return TaskRecord(
            task_id=task_id,
            repository="Vivaliz-site/amazon-returns-safet",
            host="shopvivaliz-free-a1",
            worktree_path="/srv/continuity/worktrees/amazon-returns-safet/" + task_id,
            branch="agent/" + task_id + "-continuity",
            base_sha="a" * 40,
            current_head="a" * 40,
            objective="test continuity",
            status=TaskStatus.QUEUED,
            classification=Classification.NEEDS_RESUME,
            priority=priority,
            next_action="resume",
            created_at=now,
            updated_at=now,
        )

    def test_status_and_classification_are_independent(self):
        task = self.task()
        task.status = TaskStatus.MERGED
        task.classification = Classification.MERGED_UNVERIFIED
        self.ledger.create_task(task)
        loaded = self.ledger.get_task(task.task_id)
        self.assertEqual(TaskStatus.MERGED, loaded.status)
        self.assertEqual(Classification.MERGED_UNVERIFIED, loaded.classification)

    def test_second_claim_is_rejected_while_lease_is_active(self):
        task = self.task()
        self.ledger.create_task(task)
        now = task.created_at
        self.ledger.claim(task.task_id, "codex", "session-a", now, 1800)
        with self.assertRaises(LeaseConflict):
            self.ledger.claim(task.task_id, "chatgpt", "session-b", now + timedelta(seconds=1), 1800)

    def test_heartbeat_renews_lease_and_revision(self):
        task = self.task()
        self.ledger.create_task(task)
        claimed = self.ledger.claim(task.task_id, "codex", "session-a", task.created_at, 60)
        renewed = self.ledger.heartbeat(task.task_id, "session-a", task.created_at + timedelta(seconds=30), 120)
        self.assertGreater(renewed.lease_expires_at, claimed.lease_expires_at)
        self.assertGreater(renewed.revision, claimed.revision)

    def test_expired_lease_becomes_needs_resume(self):
        task = self.task()
        self.ledger.create_task(task)
        now = task.created_at
        self.ledger.claim(task.task_id, "codex", "session-a", now, 60)
        expired = self.ledger.expire_leases(now + timedelta(seconds=61))
        self.assertEqual([task.task_id], [t.task_id for t in expired])
        loaded = self.ledger.get_task(task.task_id)
        self.assertEqual(TaskStatus.NEEDS_RESUME, loaded.status)
        self.assertEqual(Classification.NEEDS_RESUME, loaded.classification)

    def test_resume_queue_orders_by_priority_age_and_id(self):
        older = self.task("TASK-B", priority=20)
        newer = self.task("TASK-A", priority=20)
        newer.updated_at = older.updated_at + timedelta(seconds=1)
        urgent = self.task("TASK-Z", priority=10)
        for item in (older, newer, urgent):
            self.ledger.create_task(item)
        self.assertEqual(["TASK-Z", "TASK-B", "TASK-A"], [t.task_id for t in self.ledger.list_resume_queue()])


if __name__ == "__main__":
    unittest.main()
