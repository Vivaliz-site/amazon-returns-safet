from datetime import datetime, timezone
import hashlib
from pathlib import Path
import tempfile
import unittest

from tests.continuity.git_fixture import GitFixture
from tools.continuity.controller import Controller, RepoConfig
from tools.continuity.ledger import Ledger
from tools.continuity.model import Classification, TaskStatus

UTC = timezone.utc


def working_hashes(root: Path):
    result = {}
    for path in sorted(p for p in root.rglob("*") if p.is_file() and ".git" not in p.parts):
        result[str(path.relative_to(root))] = hashlib.sha256(path.read_bytes()).hexdigest()
    return result


class InitialAuditTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.ledger = Ledger(Path(self.tmp.name) / "ledger.sqlite3")
        self.config = RepoConfig(
            repository="Vivaliz-site/amazon-returns-safet", host="test-host", path=self.fx.repo,
            base_ref="origin/main", base_branch="main", github_enabled=False,
        )

    def test_audit_only_preserves_refs_and_working_files_and_reports_required_evidence(self):
        self.fx.write("tracked.txt", "committed ahead\n")
        self.fx.git("add", "tracked.txt")
        self.fx.git("commit", "-m", "local ahead")
        self.fx.write("tracked.txt", "dirty after commit\n")
        self.fx.write("untracked.txt", "do not mutate\n")
        refs_before = self.fx.git("show-ref").stdout
        files_before = working_hashes(self.fx.repo)
        status_before = self.fx.git("status", "--porcelain=v1", "-uall").stdout

        report = Controller(self.ledger).reconcile_repository(self.config, audit_only=True)

        self.assertEqual(refs_before, self.fx.git("show-ref").stdout)
        self.assertEqual(files_before, working_hashes(self.fx.repo))
        self.assertEqual(status_before, self.fx.git("status", "--porcelain=v1", "-uall").stdout)
        payload = report.as_dict()
        for key in (
            "repository", "host", "path", "branch", "head", "changed_files", "exclusive_commits",
            "upstream", "pull_request", "ci_state", "last_activity", "classification",
            "risk_priority", "next_action",
        ):
            self.assertIn(key, payload)
        self.assertEqual(1, len(payload["exclusive_commits"]))
        self.assertEqual(Classification.ORPHAN_UNKNOWN.value, payload["classification"])
        self.assertEqual(1, len(self.ledger.list_tasks()))

    def test_orphan_can_be_associated_without_moving_worktree(self):
        self.fx.write("untracked.txt", "pending\n")
        report = Controller(self.ledger).reconcile_repository(self.config, audit_only=True)
        orphan = self.ledger.get_task(report.task_id)
        associated = self.ledger.associate_orphan(
            orphan.task_id, "TASK-20260913-009", "finish recovered work",
            datetime(2026, 9, 13, 21, 30, tzinfo=UTC),
        )
        self.assertEqual(orphan.worktree_path, associated.worktree_path)
        self.assertEqual(orphan.branch, associated.branch)
        self.assertEqual(Classification.NEEDS_RESUME, associated.classification)
        self.assertEqual(TaskStatus.NEEDS_RESUME, associated.status)
        original = self.ledger.get_task(orphan.task_id)
        self.assertEqual(Classification.SUPERSEDED, original.classification)
        self.assertIn(associated.task_id, original.next_action)

        follow_up = Controller(self.ledger).reconcile_repository(self.config, audit_only=True)
        self.assertEqual(associated.task_id, follow_up.task_id)
        self.assertNotEqual(Classification.SUPERSEDED.value, follow_up.classification)


if __name__ == "__main__":
    unittest.main()
