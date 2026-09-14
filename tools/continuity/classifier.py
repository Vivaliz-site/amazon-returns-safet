from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .git_scan import GitFinding
from .model import Classification, TaskRecord, TaskStatus


@dataclass(frozen=True)
class ClassificationDecision:
    classification: Classification
    status: TaskStatus
    reason: str
    priority: int
    next_action: str


def _decision(classification: Classification, status: TaskStatus, reason: str,
              priority: int, next_action: str) -> ClassificationDecision:
    return ClassificationDecision(classification, status, reason, priority, next_action)


def _remote_value(remote: Any, name: str, default: Any = None) -> Any:
    return default if remote is None else getattr(remote, name, default)


def classify(task: TaskRecord | None, finding: GitFinding, remote: Any | None) -> ClassificationDecision:
    """Classify normalized evidence without performing I/O.

    Lease expiry is normalized by ``Ledger.expire_leases`` before this pure
    function is called. Therefore ``classification=ACTIVE`` plus a persisted
    lease is the input evidence for an active, unexpired owner.
    """
    if task and task.classification is Classification.ACTIVE and task.lease_expires_at and task.agent_session_id:
        return _decision(
            Classification.ACTIVE, task.status, "active lease is owned by an agent", 1000,
            "continue current agent execution and renew heartbeat",
        )

    if finding.operation_in_progress:
        markers = ", ".join(finding.operation_in_progress)
        return _decision(
            Classification.NEEDS_RESUME, TaskStatus.NEEDS_RESUME,
            f"interrupted git operation detected: {markers}", 10,
            "resume the interrupted git operation in the existing worktree",
        )

    if task is None and (finding.dirty or finding.stash_count or finding.detached or finding.lock_files):
        return _decision(
            Classification.ORPHAN_UNKNOWN, TaskStatus.DISCOVERED,
            "dirty work has no task association", 20,
            "triage ownership and associate the existing worktree without modifying it",
        )

    branch_present = bool(_remote_value(remote, "branch_present", False))
    pr_state = _remote_value(remote, "pr_state")
    merged = bool(_remote_value(remote, "merged", False))
    checks_state = _remote_value(remote, "checks_state")
    mergeable = _remote_value(remote, "mergeable")
    evidence_error = _remote_value(remote, "evidence_error")

    if task and task.status is TaskStatus.SUPERSEDED:
        return _decision(
            Classification.SUPERSEDED, TaskStatus.SUPERSEDED,
            "task is explicitly superseded", 900,
            "preserve evidence until replacement work is verified",
        )

    if evidence_error:
        return _decision(
            Classification.BLOCKED_EXTERNAL, TaskStatus.BLOCKED,
            f"external evidence or authorization blocker: {evidence_error}", 70,
            "resolve the external blocker, then reconcile the same task",
        )

    if task and finding.ahead_count > 0 and not branch_present and not merged:
        return _decision(
            Classification.NEEDS_RESUME, TaskStatus.NEEDS_RESUME,
            "local commits are ahead of the base and no remote task branch is present", 30,
            "validate and push the existing branch without recreating work",
        )

    if task and branch_present and not pr_state and not merged:
        return _decision(
            Classification.READY_FOR_PR, TaskStatus.READY_FOR_PR,
            "remote branch exists without a pull request", 40,
            "open a PR for the validated branch and follow required checks",
        )

    if pr_state == "OPEN" and (str(checks_state).lower() in {"failure", "failed", "error", "cancelled"} or mergeable is False):
        detail = "failed checks" if str(checks_state).lower() in {"failure", "failed", "error", "cancelled"} else "merge conflict"
        return _decision(
            Classification.PR_BLOCKED, TaskStatus.PR_OPEN,
            f"open PR is blocked by {detail}", 50,
            "fix the PR checks or conflict on the same branch and revalidate",
        )

    if merged:
        verified = bool(task and str(task.verification_state or "").upper() in {"VERIFIED", "PASS", "PASSED", "SUCCESS"})
        if verified:
            return _decision(
                Classification.DONE, TaskStatus.DONE,
                "change is merged and post-merge verification is recorded", 1000,
                "retain evidence according to policy",
            )
        return _decision(
            Classification.MERGED_UNVERIFIED, TaskStatus.MERGED,
            "change is merged but post-merge verification is missing", 60,
            "verify the deployed/integrated behavior and persist the evidence",
        )

    if task and task.blocker:
        detail = task.blocker
        return _decision(
            Classification.BLOCKED_EXTERNAL, TaskStatus.BLOCKED,
            f"external evidence or authorization blocker: {detail}", 70,
            "resolve the external blocker, then reconcile the same task",
        )

    if pr_state == "OPEN":
        return _decision(
            Classification.READY_FOR_PR, TaskStatus.PR_OPEN,
            "pull request is open and no blocking evidence is present", 40,
            "review required checks and merge the validated PR when policy allows",
        )

    if task and finding.dirty:
        return _decision(
            Classification.NEEDS_RESUME, TaskStatus.NEEDS_RESUME,
            "associated worktree contains uncommitted work", 20,
            "resume in the existing worktree and validate the pending changes",
        )

    if task:
        return _decision(
            Classification.NEEDS_RESUME, TaskStatus.NEEDS_RESUME,
            "task has not reached merged and verified completion", 80,
            "reconcile local and remote evidence and continue the existing task",
        )

    return _decision(
        Classification.ORPHAN_UNKNOWN, TaskStatus.DISCOVERED,
        "repository evidence is not associated with a known task", 90,
        "triage the repository state before making changes",
    )
