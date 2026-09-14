from pathlib import Path
import unittest

from tools.continuity.git_scan import scan_repository
from tests.continuity.git_fixture import GitFixture


class GitScanTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)

    def test_detects_dirty_staged_untracked_and_local_ahead(self):
        self.fx.write("tracked.txt", "changed\n")
        self.fx.git("add", "tracked.txt")
        self.fx.git("commit", "-m", "local ahead")
        self.fx.write("tracked.txt", "changed again\n")
        self.fx.write("staged.txt", "new\n")
        self.fx.git("add", "staged.txt")
        self.fx.write("untracked.txt", "new\n")
        finding = scan_repository(self.fx.repo)
        self.assertIn("tracked.txt", finding.modified)
        self.assertIn("staged.txt", finding.staged)
        self.assertIn("untracked.txt", finding.untracked)
        self.assertEqual(1, finding.ahead_count)
        self.assertEqual(0, finding.behind_count)

    def test_preserves_paths_with_spaces(self):
        self.fx.write("path with spaces.txt", "new\n")
        self.fx.git("add", "path with spaces.txt")
        finding = scan_repository(self.fx.repo)
        self.assertIn("path with spaces.txt", finding.staged)

    def test_detects_detached_head(self):
        self.fx.git("checkout", "--detach", "HEAD")
        finding = scan_repository(self.fx.repo)
        self.assertTrue(finding.detached)
        self.assertIsNone(finding.branch)

    def test_detects_stash_and_worktree(self):
        self.fx.write("tracked.txt", "stash me\n")
        self.fx.git("stash", "push", "-m", "fixture")
        finding = scan_repository(self.fx.repo)
        self.assertEqual(1, finding.stash_count)
        self.assertTrue(any(Path(item).resolve() == self.fx.repo.resolve() for item in finding.worktrees))

    def test_detects_real_git_operation_markers(self):
        for marker in ("MERGE_HEAD", "rebase-merge", "rebase-apply", "CHERRY_PICK_HEAD", "REVERT_HEAD", "BISECT_LOG"):
            path = self.fx.create_marker(marker)
            finding = scan_repository(self.fx.repo)
            self.assertIn(marker, finding.operation_in_progress)
            if path.is_dir():
                path.rmdir()
            else:
                path.unlink()

    def test_reports_conflicted_entries(self):
        self.fx.write("tracked.txt", "left\n")
        self.fx.git("checkout", "-b", "other")
        self.fx.commit("other change")
        self.fx.git("checkout", "main")
        self.fx.write("tracked.txt", "right\n")
        self.fx.commit("main change")
        self.fx.git("merge", "other", check=False)
        finding = scan_repository(self.fx.repo)
        self.assertIn("tracked.txt", finding.conflicted)
        self.assertIn("MERGE_HEAD", finding.operation_in_progress)


if __name__ == "__main__":
    unittest.main()
