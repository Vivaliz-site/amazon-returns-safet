from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timezone
from enum import Enum
from typing import Any

UTC = timezone.utc


class TaskStatus(str, Enum):
    DISCOVERED = "DISCOVERED"
    QUEUED = "QUEUED"
    CLAIMED = "CLAIMED"
    IMPLEMENTING = "IMPLEMENTING"
    TESTING = "TESTING"
    READY_FOR_PR = "READY_FOR_PR"
    PR_OPEN = "PR_OPEN"
    MERGING = "MERGING"
    MERGED = "MERGED"
    VERIFYING = "VERIFYING"
    VERIFIED = "VERIFIED"
    DONE = "DONE"
    BLOCKED = "BLOCKED"
    AGENT_LOST = "AGENT_LOST"
    NEEDS_RESUME = "NEEDS_RESUME"
    SUPERSEDED = "SUPERSEDED"


class Classification(str, Enum):
    ACTIVE = "ACTIVE"
    NEEDS_RESUME = "NEEDS_RESUME"
    READY_FOR_PR = "READY_FOR_PR"
    PR_BLOCKED = "PR_BLOCKED"
    BLOCKED_EXTERNAL = "BLOCKED_EXTERNAL"
    SUPERSEDED = "SUPERSEDED"
    MERGED_UNVERIFIED = "MERGED_UNVERIFIED"
    DONE = "DONE"
    ORPHAN_UNKNOWN = "ORPHAN_UNKNOWN"


def utcnow() -> datetime:
    return datetime.now(tz=UTC)


def ensure_utc(value: datetime | None) -> datetime | None:
    if value is None:
        return None
    if value.tzinfo is None:
        return value.replace(tzinfo=UTC)
    return value.astimezone(UTC)


@dataclass
class TaskRecord:
    task_id: str
    repository: str
    host: str
    worktree_path: str
    branch: str
    base_sha: str
    current_head: str
    objective: str
    status: TaskStatus = TaskStatus.DISCOVERED
    classification: Classification = Classification.ORPHAN_UNKNOWN
    agent_type: str | None = None
    agent_session_id: str | None = None
    last_heartbeat_at: datetime | None = None
    lease_expires_at: datetime | None = None
    last_checkpoint_sha: str | None = None
    dirty_files: list[str] = field(default_factory=list)
    staged_files: list[str] = field(default_factory=list)
    untracked_files: list[str] = field(default_factory=list)
    ahead_count: int = 0
    behind_count: int = 0
    pull_request: str | None = None
    ci_state: str | None = None
    verification_state: str | None = None
    blocker: str | None = None
    next_action: str | None = None
    priority: int = 1000
    local_test_results: list[str] = field(default_factory=list)
    known_failures: list[str] = field(default_factory=list)
    patch_summary: str | None = None
    created_at: datetime = field(default_factory=utcnow)
    updated_at: datetime = field(default_factory=utcnow)
    revision: int = 0

    def as_public_dict(self) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in self.__dict__.items():
            if isinstance(value, Enum):
                result[key] = value.value
            elif isinstance(value, datetime):
                result[key] = ensure_utc(value).isoformat()
            else:
                result[key] = value
        return result
