from __future__ import annotations

import json
import subprocess
import unittest

from tools.continuity.github_state import GitHubStateReader


class FakeRunner:
    def __init__(self, branch=None, prs=None, branch_error=None):
        self.branch = branch if branch is not None else {"object": {"sha": "b" * 40}}
        self.prs = [] if prs is None else prs
        self.branch_error = branch_error
        self.calls = []

    def __call__(self, args):
        self.calls.append(list(args))
        if args[:2] == ["gh", "api"]:
            if self.branch_error:
                return subprocess.CompletedProcess(args, 1, "", self.branch_error)
            return subprocess.CompletedProcess(args, 0, json.dumps(self.branch), "")
        if args[:3] == ["gh", "pr", "list"]:
            return subprocess.CompletedProcess(args, 0, json.dumps(self.prs), "")
        raise AssertionError(f"unexpected command: {args}")


def pr(state="OPEN", merged_at=None, merge_state="CLEAN", checks=None):
    return {
        "number": 42,
        "url": "https://github.example.invalid/pull/42",
        "state": state,
        "mergedAt": merged_at,
        "mergeStateStatus": merge_state,
        "headRefOid": "b" * 40,
        "baseRefName": "main",
        "statusCheckRollup": checks if checks is not None else [
            {"status": "COMPLETED", "conclusion": "SUCCESS", "name": "test"}
        ],
    }


class GitHubStateReaderTest(unittest.TestCase):
    def test_branch_present_without_pr(self):
        state = GitHubStateReader(FakeRunner()).branch_state("org/repo", "agent/task", "main")
        self.assertTrue(state.branch_present)
        self.assertIsNone(state.pr_state)
        self.assertFalse(state.merged)
        self.assertEqual("b" * 40, state.head_sha)

    def test_open_green_pr(self):
        state = GitHubStateReader(FakeRunner(prs=[pr()])).branch_state("org/repo", "agent/task", "main")
        self.assertEqual("OPEN", state.pr_state)
        self.assertEqual("success", state.checks_state)
        self.assertTrue(state.mergeable)
        self.assertFalse(state.merged)

    def test_open_failed_pr(self):
        checks = [{"status": "COMPLETED", "conclusion": "FAILURE", "name": "test"}]
        state = GitHubStateReader(FakeRunner(prs=[pr(checks=checks)])).branch_state("org/repo", "agent/task", "main")
        self.assertEqual("failure", state.checks_state)

    def test_open_conflicted_pr(self):
        state = GitHubStateReader(FakeRunner(prs=[pr(merge_state="DIRTY")])).branch_state("org/repo", "agent/task", "main")
        self.assertFalse(state.mergeable)

    def test_merged_pr(self):
        state = GitHubStateReader(FakeRunner(prs=[pr(state="MERGED", merged_at="2026-09-13T21:00:00Z")])).branch_state("org/repo", "agent/task", "main")
        self.assertTrue(state.merged)
        self.assertEqual("MERGED", state.pr_state)

    def test_closed_unmerged_pr(self):
        state = GitHubStateReader(FakeRunner(prs=[pr(state="CLOSED", merged_at=None)])).branch_state("org/repo", "agent/task", "main")
        self.assertFalse(state.merged)
        self.assertTrue(state.closed_unmerged)

    def test_unavailable_credentials_are_redacted_evidence_error(self):
        secret = "ghp_" + "SYNTHETIC_NOT_REAL_1234567890"
        runner = FakeRunner(branch_error=f"HTTP 401 Bad credentials {secret}")
        state = GitHubStateReader(runner).branch_state("org/repo", "agent/task", "main")
        self.assertIsNotNone(state.evidence_error)
        self.assertNotIn(secret, state.evidence_error)
        self.assertIn("[REDACTED]", state.evidence_error)
        self.assertFalse(state.merged)


if __name__ == "__main__":
    unittest.main()


class FakePublicFetcher:
    def __init__(self):
        self.urls = []

    def __call__(self, url):
        self.urls.append(url)
        if "/git/ref/heads/" in url:
            return 200, json.dumps({"object": {"sha": "b" * 40}})
        if "/pulls?" in url:
            return 200, json.dumps([{"number": 42, "html_url": "https://github.com/org/repo/pull/42", "state": "open", "head": {"sha": "b" * 40}}])
        if url.endswith("/pulls/42"):
            return 200, json.dumps({"number": 42, "html_url": "https://github.com/org/repo/pull/42", "state": "open", "merged": False, "mergeable": True, "head": {"sha": "b" * 40}})
        if "/check-runs" in url:
            return 200, json.dumps({"check_runs": [{"status": "completed", "conclusion": "success", "name": "test"}]})
        raise AssertionError(f"unexpected public URL: {url}")


class PublicGitHubStateReaderTest(unittest.TestCase):
    def test_default_public_reader_uses_anonymous_get_only(self):
        fetcher = FakePublicFetcher()
        state = GitHubStateReader(public_fetcher=fetcher).branch_state("org/repo", "agent/task", "main")
        self.assertTrue(state.branch_present)
        self.assertEqual("OPEN", state.pr_state)
        self.assertEqual("success", state.checks_state)
        self.assertTrue(state.mergeable)
        self.assertTrue(all(url.startswith("https://api.github.com/") for url in fetcher.urls))
        self.assertTrue(all("token" not in url.lower() for url in fetcher.urls))

    def test_public_reader_redacts_http_error_body(self):
        secret = "ghp_" + "SYNTHETIC_NOT_REAL_1234567890"
        reader = GitHubStateReader(public_fetcher=lambda url: (403, f"denied {secret}"))
        state = reader.branch_state("org/repo", "agent/task", "main")
        self.assertIn("[REDACTED]", state.evidence_error)
        self.assertNotIn(secret, state.evidence_error)
