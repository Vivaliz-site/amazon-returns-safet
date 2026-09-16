from pathlib import Path
import tempfile
import unittest
from datetime import datetime, timezone

from tests.continuity.git_fixture import GitFixture
from tools.continuity.controller import Controller, RepoConfig
from tools.continuity.ledger import Ledger
from tools.continuity.model import Classification, TaskRecord, TaskStatus


class ControllerTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.ledger = Ledger(Path(self.tmp.name) / "ledger.sqlite3")
        self.controller = Controller(self.ledger)
        self.config = RepoConfig(
            repository="Vivaliz-site/amazon-returns-safet",
            host="test-host",
            path=self.fx.repo,
            base_ref="origin/main",
            base_branch="main",
            github_enabled=False,
        )

    def test_reconciliation_is_idempotent_and_orphan_stays_read_only(self):
        self.fx.write("orphan.txt", "unfinished\n")
        first = self.controller.reconcile_repository(self.config, audit_only=True)
        tasks = self.ledger.list_tasks()
        self.assertEqual(1, len(tasks))
        self.assertEqual(Classification.ORPHAN_UNKNOWN, tasks[0].classification)
        first_revision = tasks[0].revision

        second = self.controller.reconcile_repository(self.config, audit_only=True)
        loaded = self.ledger.get_task(tasks[0].task_id)
        self.assertEqual(first.task_id, second.task_id)
        self.assertEqual(first_revision, loaded.revision)
        self.assertEqual(Classification.ORPHAN_UNKNOWN, loaded.classification)
        self.assertEqual("unfinished\n", (self.fx.repo / "orphan.txt").read_text())

    def test_revision_changes_only_when_evidence_changes(self):
        self.fx.write("orphan.txt", "unfinished\n")
        report = self.controller.reconcile_repository(self.config, audit_only=True)
        before = self.ledger.get_task(report.task_id)
        self.fx.write("second.txt", "more work\n")
        self.controller.reconcile_repository(self.config, audit_only=True)
        after = self.ledger.get_task(report.task_id)
        self.assertGreater(after.revision, before.revision)

    def test_reconcile_expires_stale_lease_before_classification(self):
        old = datetime(2000, 1, 1, tzinfo=timezone.utc)
        task = TaskRecord(
            task_id="TASK-STALE", repository=self.config.repository, host=self.config.host,
            worktree_path=str(self.fx.repo), branch="main",
            base_sha=self.fx.git("rev-parse", "origin/main").stdout.strip(),
            current_head=self.fx.git("rev-parse", "HEAD").stdout.strip(), objective="stale lease",
            status=TaskStatus.NEEDS_RESUME, classification=Classification.NEEDS_RESUME,
            created_at=old, updated_at=old,
        )
        self.ledger.create_task(task)
        self.ledger.claim(task.task_id, "codex", "stale-session", old, 60)
        self.controller.reconcile_repository(self.config, audit_only=True)
        loaded = self.ledger.get_task(task.task_id)
        self.assertNotEqual(Classification.ACTIVE, loaded.classification)
        self.assertIsNone(loaded.lease_expires_at)

    def test_clean_base_does_not_create_orphan_task(self):
        report = self.controller.reconcile_repository(self.config, audit_only=True)
        self.assertIsNone(report.task_id)
        self.assertEqual([], self.ledger.list_tasks())

    def test_default_controller_uses_public_read_only_github_reader(self):
        reader = self.controller.github_reader
        self.assertIsNone(reader.runner)
        self.assertIsNotNone(reader.public_fetcher)



if __name__ == "__main__":
    unittest.main()
