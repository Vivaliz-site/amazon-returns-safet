from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
import subprocess
from typing import Callable, Mapping, Sequence

from .git_scan import scan_repository
from .ledger import Ledger, LeaseConflict
from .model import Classification, TaskStatus, ensure_utc
from .resume_packet import render_resume_packet

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
    ):
        self.ledger = ledger
        self.candidates = list(candidates)
        self.auto_dispatch = bool(auto_dispatch)
        self.availability = dict(availability or {})
        self.launcher = launcher or _default_launcher
        self.lease_seconds = lease_seconds
        self.retry_cooldown_seconds = retry_cooldown_seconds

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
        for attempt in self.ledger.list_dispatch_attempts(task_id):
            if attempt.get("outcome") != "launch_failed":
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

    def claim_next(self, now: datetime) -> DispatchDecision | None:
        now = ensure_utc(now)
        assert now is not None
        task = self._next_resumable()
        if task is None:
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

        session_id = f"continuity-{task.task_id}-{agent.name}-{int(now.timestamp() * 1000000)}"
        try:
            claimed = self.ledger.claim(task.task_id, agent.name, session_id, now, self.lease_seconds)
        except LeaseConflict:
            return None
        packet = render_resume_packet(claimed, finding, None)
        launched = self.launcher(agent, packet, Path(task.worktree_path))
        if launched:
            self.ledger.record_dispatch_attempt(task.task_id, agent.name, now, "worker_completed")
            self.ledger.update_fields(
                task.task_id,
                status=TaskStatus.NEEDS_RESUME,
                classification=Classification.NEEDS_RESUME,
                agent_session_id=None,
                lease_expires_at=None,
                next_action=f"reconcile evidence after {agent.name} worker completion",
                updated_at=now,
            )
            return DispatchDecision(task.task_id, agent, tuple(agent.command), packet, True, True, session_id)

        self.ledger.record_dispatch_attempt(task.task_id, agent.name, now, "launch_failed", "launcher returned false")
        self.ledger.update_fields(
            task.task_id,
            status=TaskStatus.NEEDS_RESUME,
            classification=Classification.NEEDS_RESUME,
            agent_session_id=None,
            lease_expires_at=None,
            next_action=f"retry resume using fallback after {agent.name} launch failure",
            updated_at=now,
        )
        return DispatchDecision(task.task_id, agent, tuple(agent.command), packet, True, False, session_id)
