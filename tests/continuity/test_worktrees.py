import os
from pathlib import Path
import tempfile
import unittest

from tests.continuity.git_fixture import GitFixture
from tools.continuity.worktrees import (
    WorktreeSafetyError,
    assert_worker_owned_worktree,
    create_task_worktree,
    create_worker_task_worktree,
    prepare_worker_source,
)


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

    def test_worker_worktree_must_live_below_root_and_match_owner(self):
        with self.assertRaises(WorktreeSafetyError):
            assert_worker_owned_worktree(self.fx.repo, self.root / "worktrees", worker_uid=os.getuid())

    def test_worker_source_is_bare_and_task_path_is_unique(self):
        sources = self.root / "sources"
        source = prepare_worker_source(str(self.fx.remote), sources, "Vivaliz-site/amazon-returns-safet")
        self.assertTrue((source / "HEAD").exists())
        self.assertEqual("true", self.fx.git("-C", str(source), "rev-parse", "--is-bare-repository").stdout.strip())
        result = create_worker_task_worktree(
            source, self.root, "TASK-20260916-002", "pilot", "origin/main", os.getuid()
        )
        self.assertTrue(result.path.is_relative_to(self.root / "worktrees"))
        assert_worker_owned_worktree(result.path, self.root / "worktrees", os.getuid())
        with self.assertRaises(WorktreeSafetyError):
            create_worker_task_worktree(source, self.root, "TASK-20260916-002", "pilot", "origin/main", os.getuid())

    def test_worker_root_symlink_is_rejected(self):
        real = self.root / "real"
        real.mkdir()
        link = self.root / "linked"
        link.symlink_to(real, target_is_directory=True)
        with self.assertRaises(WorktreeSafetyError):
            assert_worker_owned_worktree(self.fx.repo, link, worker_uid=os.getuid())


if __name__ == "__main__":
    unittest.main()
