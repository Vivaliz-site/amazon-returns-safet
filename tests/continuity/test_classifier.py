from datetime import datetime, timezone
from types import SimpleNamespace
import unittest

from tools.continuity.classifier import classify
from tools.continuity.git_scan import GitFinding
from tools.continuity.model import Classification, TaskRecord, TaskStatus

UTC = timezone.utc


def finding(**changes):
    data = dict(
        repo="/tmp/repo",
        branch="agent/TASK-x",
        head="b" * 40,
        upstream="origin/agent/TASK-x",
        modified=(), staged=(), untracked=(), stash_count=0,
        ahead_count=0, behind_count=0, detached=False,
        operation_in_progress=(), conflicted=(), worktrees=("/tmp/repo",), lock_files=(),
    )
    data.update(changes)
    return GitFinding(**data)


def task(**changes):
    now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)
    data = dict(
        task_id="TASK-20260913-001", repository="Vivaliz-site/amazon-returns-safet",
        host="shopvivaliz-free-a1", worktree_path="/tmp/repo", branch="agent/TASK-x",
        base_sha="a" * 40, current_head="b" * 40, objective="continue safely",
        status=TaskStatus.QUEUED, classification=Classification.NEEDS_RESUME,
        created_at=now, updated_at=now,
    )
    data.update(changes)
    return TaskRecord(**data)


class ClassifierTest(unittest.TestCase):
    def test_active_normalized_lease_wins(self):
        t = task(classification=Classification.ACTIVE, status=TaskStatus.CLAIMED,
                 agent_session_id="session-a", lease_expires_at=datetime(2026, 9, 13, 22, 0, tzinfo=UTC))
        result = classify(t, finding(operation_in_progress=("MERGE_HEAD",)), None)
        self.assertEqual(Classification.ACTIVE, result.classification)

    def test_interrupted_git_operation_is_highest_resume_risk(self):
        result = classify(task(), finding(operation_in_progress=("MERGE_HEAD",)), None)
        self.assertEqual((Classification.NEEDS_RESUME, TaskStatus.NEEDS_RESUME, 10),
                         (result.classification, result.status, result.priority))
        self.assertIn("interrupted", result.next_action.lower())

    def test_unowned_dirty_work_is_orphan_unknown(self):
        result = classify(None, finding(modified=("a.txt",)), None)
        self.assertEqual(Classification.ORPHAN_UNKNOWN, result.classification)
        self.assertEqual(20, result.priority)
        self.assertIn("triage", result.next_action.lower())

    def test_unowned_stash_is_orphan_risk_even_when_worktree_is_clean(self):
        result = classify(None, finding(stash_count=1), None)
        self.assertEqual(Classification.ORPHAN_UNKNOWN, result.classification)
        self.assertEqual(20, result.priority)

    def test_unpushed_local_commits_need_resume(self):
        remote = SimpleNamespace(branch_present=False, pr_state=None, merged=False,
                                 checks_state=None, mergeable=None, evidence_error=None)
        result = classify(task(), finding(ahead_count=2), remote)
        self.assertEqual(Classification.NEEDS_RESUME, result.classification)
        self.assertEqual(30, result.priority)
        self.assertIn("push", result.next_action.lower())

    def test_pushed_branch_without_pr_is_ready_for_pr(self):
        remote = SimpleNamespace(branch_present=True, pr_state=None, merged=False,
                                 checks_state="success", mergeable=True, evidence_error=None)
        result = classify(task(), finding(ahead_count=2), remote)
        self.assertEqual(Classification.READY_FOR_PR, result.classification)
        self.assertEqual(40, result.priority)
        self.assertIn("pr", result.next_action.lower())

    def test_open_pr_with_failed_checks_is_blocked(self):
        remote = SimpleNamespace(branch_present=True, pr_state="OPEN", merged=False,
                                 checks_state="failure", mergeable=True, evidence_error=None)
        result = classify(task(), finding(), remote)
        self.assertEqual(Classification.PR_BLOCKED, result.classification)
        self.assertEqual(50, result.priority)
        self.assertIn("check", result.next_action.lower())

    def test_open_pr_with_conflict_is_blocked(self):
        remote = SimpleNamespace(branch_present=True, pr_state="OPEN", merged=False,
                                 checks_state="success", mergeable=False, evidence_error=None)
        result = classify(task(), finding(), remote)
        self.assertEqual(Classification.PR_BLOCKED, result.classification)

    def test_merged_pr_is_not_mistaken_for_unpushed_when_remote_branch_was_deleted(self):
        remote = SimpleNamespace(branch_present=False, pr_state="MERGED", merged=True,
                                 checks_state="success", mergeable=True, evidence_error=None)
        result = classify(task(verification_state="pending"), finding(ahead_count=1), remote)
        self.assertEqual(Classification.MERGED_UNVERIFIED, result.classification)

    def test_merged_without_verification_is_unverified(self):
        remote = SimpleNamespace(branch_present=True, pr_state="MERGED", merged=True,
                                 checks_state="success", mergeable=True, evidence_error=None)
        result = classify(task(verification_state="pending"), finding(), remote)
        self.assertEqual(Classification.MERGED_UNVERIFIED, result.classification)
        self.assertEqual(60, result.priority)

    def test_merged_and_verified_is_done(self):
        remote = SimpleNamespace(branch_present=True, pr_state="MERGED", merged=True,
                                 checks_state="success", mergeable=True, evidence_error=None)
        result = classify(task(verification_state="VERIFIED"), finding(), remote)
        self.assertEqual(Classification.DONE, result.classification)
        self.assertEqual(TaskStatus.DONE, result.status)
        self.assertEqual(1000, result.priority)

    def test_remote_evidence_error_does_not_masquerade_as_unpushed_commits(self):
        remote = SimpleNamespace(branch_present=False, pr_state=None, merged=False,
                                 checks_state=None, mergeable=None, evidence_error="gh auth unavailable")
        result = classify(task(), finding(ahead_count=2), remote)
        self.assertEqual(Classification.BLOCKED_EXTERNAL, result.classification)
        self.assertIn("gh auth unavailable", result.reason)

    def test_evidence_error_or_explicit_blocker_is_external_block(self):
        remote = SimpleNamespace(branch_present=False, pr_state=None, merged=False,
                                 checks_state=None, mergeable=None, evidence_error="gh auth unavailable")
        result = classify(task(), finding(), remote)
        self.assertEqual(Classification.BLOCKED_EXTERNAL, result.classification)
        self.assertIn("external", result.next_action.lower())
        result = classify(task(blocker="MFA required"), finding(), None)
        self.assertEqual(Classification.BLOCKED_EXTERNAL, result.classification)


if __name__ == "__main__":
    unittest.main()
