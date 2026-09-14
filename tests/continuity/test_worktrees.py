from pathlib import Path
import tempfile
import unittest

from tests.continuity.git_fixture import GitFixture
from tools.continuity.worktrees import WorktreeSafetyError, create_task_worktree


class WorktreeTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)

    def test_creates_isolated_task_path_and_branch(self):
        result = create_task_worktree(
            self.fx.repo, self.root, "TASK-20260913-006", "continuity-controller", "origin/main"
        )
        expected = self.root / "worktrees" / self.fx.repo.name / "TASK-20260913-006"
        self.assertEqual(expected.resolve(), result.path.resolve())
        self.assertEqual("agent/TASK-20260913-006-continuity-controller", result.branch)
        self.assertTrue((result.path / ".git").exists())
        branch = self.fx.git("-C", str(result.path), "branch", "--show-current").stdout.strip()
        self.assertEqual(result.branch, branch)

    def test_refuses_unknown_existing_target_content(self):
        target = self.root / "worktrees" / self.fx.repo.name / "TASK-20260913-006"
        target.mkdir(parents=True)
        (target / "unknown.txt").write_text("do not overwrite", encoding="utf-8")
        with self.assertRaises(WorktreeSafetyError):
            create_task_worktree(self.fx.repo, self.root, "TASK-20260913-006", "continuity", "origin/main")
        self.assertEqual("do not overwrite", (target / "unknown.txt").read_text())

    def test_refuses_deploy_checkout_marker(self):
        (self.fx.repo / ".release-sha").write_text("deadbeef\n", encoding="utf-8")
        with self.assertRaises(WorktreeSafetyError):
            create_task_worktree(self.fx.repo, self.root, "TASK-20260913-006", "continuity", "origin/main")


if __name__ == "__main__":
    unittest.main()
