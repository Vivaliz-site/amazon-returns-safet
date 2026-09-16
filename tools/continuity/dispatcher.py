from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
import hashlib
import os
from pathlib import Path
import subprocess
from typing import Callable, Mapping, Sequence

from .git_scan import scan_repository
from .job_queue import JobEnvelope, QueuePaths, atomic_write_job
from .ledger import Ledger, LeaseConflict
from .model import Classification, TaskStatus, ensure_utc
from .resume_packet import render_resume_packet
from .worktrees import WorktreeSafetyError, assert_worker_owned_worktree

UTC = timezone.utc
PREFERRED_ORDER = ("codex", "chatgpt", "claude", "gemini", "rooter")
AUTOMATED_ALLOWED_AGENTS = frozenset(("gemini", "rooter"))


@dataclass(frozen=True)
class AgentCandidate:
    name: str
    command: list[str]
    enabled: bool = True


@dataclass(frozen=True)
class DispatchDecision:
    task_id: str
    agent: AgentCandidate
    command: tuple[str, ...]
    resume_packet: str
    claimed: bool
    launched: bool
    session_id: str | None = None
    queued: bool = False
    job_id: str | None = None


Launcher = Callable[[AgentCandidate, str, Path], bool]


def _safe_command(candidate: AgentCandidate) -> bool:
    joined = " ".join(candidate.command).lower()
    forbidden = ("--token", "--password", "authorization:", "openai_api_key", "cookie=", "ghp_", "github_pat_", "sk-")
    return bool(candidate.command) and not any(marker in joined for marker in forbidden)


def _default_launcher(candidate: AgentCandidate, packet: str, cwd: Path) -> bool:
    try:
        completed = subprocess.run(
            candidate.command,
            input=packet,
            cwd=cwd,
            text=True,
            close_fds=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        )
        return completed.returncode == 0
    except (OSError, ValueError):
        return False


def _atomic_write_packet(paths: QueuePaths, job_id: str, packet: str) -> Path:
    directory = paths.root / "packets"
    directory.mkdir(parents=True, exist_ok=True)
    target = directory / f"{job_id}.txt"
    fd = os.open(target, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o640)
    os.fchmod(fd, 0o640)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        handle.write(packet)
        handle.flush()
        os.fsync(handle.fileno())
    return target


class Dispatcher:
    def __init__(
        self,
        ledger: Ledger,
        candidates: Sequence[AgentCandidate],
        *,
        auto_dispatch: bool = False,
        availability: Mapping[str, bool] | None = None,
        launcher: Launcher | None = None,
        lease_seconds: int = 1800,
        retry_cooldown_seconds: int = 1800,
        queue_paths: QueuePaths | None = None,
        worker_root: Path | None = None,
        worker_uid: int | None = None,
        max_auto_attempts: int = 3,
    ):
        self.ledger = ledger
        self.candidates = list(candidates)
        self.auto_dispatch = bool(auto_dispatch)
        self.availability = dict(availability or {})
        self.launcher = launcher or _default_launcher
        self.lease_seconds = lease_seconds
        self.retry_cooldown_seconds = retry_cooldown_seconds
        self.queue_paths = queue_paths
        self.worker_root = Path(worker_root) if worker_root is not None else None
        self.worker_uid = worker_uid
        self.max_auto_attempts = max(1, int(max_auto_attempts))

    @staticmethod
    def select_agent(candidates: Sequence[AgentCandidate], availability: Mapping[str, bool]) -> AgentCandidate | None:
        by_name = {candidate.name.lower(): candidate for candidate in candidates if candidate.enabled and _safe_command(candidate)}
        for name in PREFERRED_ORDER:
            candidate = by_name.get(name)
            if candidate is not None and bool(availability.get(name, False)):
                return candidate
        return None

    def _next_resumable(self):
        allowed = {
            Classification.NEEDS_RESUME,
            Classification.READY_FOR_PR,
            Classification.PR_BLOCKED,
            Classification.MERGED_UNVERIFIED,
        }
        return next((task for task in self.ledger.list_resume_queue() if task.classification in allowed), None)

    def _recent_failed_agents(self, task_id: str, now: datetime) -> set[str]:
        failed: set[str] = set()
        failure_outcomes = {"launch_failed", "worker_failed", "worker_timeout", "queue_failed"}
        for attempt in self.ledger.list_dispatch_attempts(task_id):
            if str(attempt.get("outcome") or "") not in failure_outcomes:
                continue
            raw = str(attempt.get("attempted_at") or "")
            try:
                attempted_at = datetime.fromisoformat(raw).astimezone(UTC)
            except ValueError:
                continue
            age = (now - attempted_at).total_seconds()
            if 0 <= age < self.retry_cooldown_seconds:
                failed.add(str(attempt.get("agent_type") or "").lower())
        return failed

    def _automatic_attempt_count(self, task_id: str) -> int:
        return sum(
            1 for attempt in self.ledger.list_dispatch_attempts(task_id)
            if str(attempt.get("agent_type") or "").lower() in AUTOMATED_ALLOWED_AGENTS
            and str(attempt.get("outcome") or "") in {
                "dispatch_queued", "launch_failed", "worker_failed", "worker_timeout", "queue_failed"
            }
        )

    def claim_next(self, now: datetime) -> DispatchDecision | None:
        now = ensure_utc(now)
        assert now is not None
        task = self._next_resumable()
        if task is None:
            return None
        if self.auto_dispatch and self._automatic_attempt_count(task.task_id) >= self.max_auto_attempts:
            self.ledger.update_fields(
                task.task_id,
                status=TaskStatus.BLOCKED,
                classification=Classification.BLOCKED_EXTERNAL,
                agent_session_id=None,
                lease_expires_at=None,
                blocker="automatic retry budget exhausted",
                next_action="review recurring automation before another attempt",
                updated_at=now,
            )
            return None
        availability = dict(self.availability)
        for failed_agent in self._recent_failed_agents(task.task_id, now):
            availability[failed_agent] = False
        candidates = self.candidates
        if self.auto_dispatch:
            candidates = [candidate for candidate in candidates if candidate.name.lower() in AUTOMATED_ALLOWED_AGENTS]
        agent = self.select_agent(candidates, availability)
        if agent is None:
            return None
        finding = scan_repository(Path(task.worktree_path))
        if not self.auto_dispatch:
            packet = render_resume_packet(task, finding, None)
            return DispatchDecision(task.task_id, agent, tuple(agent.command), packet, False, False, None)

        if self.queue_paths is None or self.worker_root is None or self.worker_uid is None:
            return None
        try:
            assert_worker_owned_worktree(Path(task.worktree_path), self.worker_root, self.worker_uid)
        except WorktreeSafetyError:
            return None
        if finding.head != task.current_head:
            return None

        session_id = f"continuity-{task.task_id}-{agent.name}-{int(now.timestamp() * 1000000)}"
        try:
            claimed = self.ledger.claim(task.task_id, agent.name, session_id, now, self.lease_seconds)
        except LeaseConflict:
            return None
        packet = render_resume_packet(claimed, finding, None)
        job_id = f"{task.task_id}--{session_id}"
        packet_path: Path | None = None
        try:
            packet_path = _atomic_write_packet(self.queue_paths, job_id, packet)
            envelope = JobEnvelope(
                task_id=task.task_id,
                repository=task.repository,
                worktree_path=task.worktree_path,
                branch=task.branch,
                expected_head=finding.head,
                base_sha=task.base_sha,
                lease_session_id=session_id,
                provider=agent.name.lower(),
                resume_packet_sha256=hashlib.sha256(packet.encode("utf-8")).hexdigest(),
                resume_packet_path=str(packet_path),
                created_at=now.isoformat(),
                deadline_at=(claimed.lease_expires_at or (now + timedelta(seconds=self.lease_seconds))).isoformat(),
            )
            job_path = atomic_write_job(self.queue_paths, envelope)
        except Exception:
            if packet_path is not None:
                packet_path.unlink(missing_ok=True)
            self.ledger.record_dispatch_attempt(task.task_id, agent.name, now, "queue_failed", "job queue write failed")
            self.ledger.update_fields(
                task.task_id, status=TaskStatus.NEEDS_RESUME, classification=Classification.NEEDS_RESUME,
                agent_session_id=None, lease_expires_at=None,
                next_action="retry after queue failure", updated_at=now,
            )
            return None
        job_id = job_path.stem
        self.ledger.record_dispatch_attempt(task.task_id, agent.name, now, "dispatch_queued", job_id)
        return DispatchDecision(
            task.task_id, agent, tuple(agent.command), packet, True, False,
            session_id=session_id, queued=True, job_id=job_id,
        )
