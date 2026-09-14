from __future__ import annotations

from dataclasses import asdict, dataclass
from datetime import datetime, timezone
import hashlib
from pathlib import Path
import subprocess
from typing import Any

from .classifier import classify
from .git_scan import GitFinding, scan_repository
from .github_state import GitHubStateReader, RemoteState
from .ledger import Ledger
from .model import Classification, TaskRecord, TaskStatus

UTC = timezone.utc


@dataclass(frozen=True)
class RepoConfig:
    repository: str
    host: str
    path: Path
    base_ref: str = "origin/main"
    base_branch: str = "main"
    github_enabled: bool = True

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "RepoConfig":
        return cls(
            repository=str(data["repository"]), host=str(data["host"]), path=Path(data["path"]),
            base_ref=str(data.get("base_ref", "origin/main")),
            base_branch=str(data.get("base_branch", "main")),
            github_enabled=bool(data.get("github_enabled", True)),
        )


@dataclass(frozen=True)
class ReconcileReport:
    repository: str
    host: str
    path: str
    branch: str | None
    head: str
    upstream: str | None
    changed_files: tuple[str, ...]
    exclusive_commits: tuple[str, ...]
    last_activity: str | None
    staged_files: tuple[str, ...]
    untracked_files: tuple[str, ...]
    stash_count: int
    ahead_count: int
    behind_count: int
    operation_in_progress: tuple[str, ...]
    task_id: str | None
    classification: str | None
    risk_priority: int | None
    next_action: str | None
    pull_request: str | None
    ci_state: str | None
    audit_only: bool

    def as_dict(self) -> dict[str, Any]:
        return asdict(self)


class Controller:
    def __init__(self, ledger: Ledger, github_reader: GitHubStateReader | None = None):
        self.ledger = ledger
        self.github_reader = github_reader or GitHubStateReader()

    @staticmethod
    def _base_sha(config: RepoConfig) -> str:
        cp = subprocess.run(
            ["git", "rev-parse", "--verify", config.base_ref], cwd=config.path,
            text=True, capture_output=True,
        )
        return cp.stdout.strip() if cp.returncode == 0 else ""

    @staticmethod
    def _actionable(finding: GitFinding) -> bool:
        return bool(
            finding.dirty or finding.stash_count or finding.ahead_count or finding.operation_in_progress
            or finding.detached or finding.lock_files
        )

    @staticmethod
    def _stable_orphan_id(config: RepoConfig, finding: GitFinding) -> str:
        identity = "|".join((config.repository, config.host, str(Path(finding.repo).resolve()), finding.branch or "DETACHED"))
        return "ORPHAN-" + hashlib.sha256(identity.encode("utf-8")).hexdigest()[:16].upper()

    def _find_existing(self, config: RepoConfig, finding: GitFinding) -> TaskRecord | None:
        path = str(Path(finding.repo).resolve())
        matches: list[TaskRecord] = []
        for task in self.ledger.list_tasks():
            if task.repository != config.repository or task.host != config.host:
                continue
            same_path = str(Path(task.worktree_path).resolve()) == path
            same_branch = finding.branch is None or task.branch == finding.branch
            if same_path and same_branch:
                matches.append(task)
        active = [
            task for task in matches
            if task.status is not TaskStatus.SUPERSEDED and task.classification is not Classification.SUPERSEDED
        ]
        if active:
            return active[0]
        orphan = self.ledger.get_task(self._stable_orphan_id(config, finding))
        if orphan and orphan.classification is not Classification.SUPERSEDED:
            return orphan
        return None

    def _remote(self, config: RepoConfig, finding: GitFinding) -> RemoteState | None:
        if not config.github_enabled or not finding.branch:
            return None
        return self.github_reader.branch_state(config.repository, finding.branch, config.base_branch)

    @staticmethod
    def _git_lines(config: RepoConfig, args: list[str]) -> tuple[str, ...]:
        cp = subprocess.run(["git", *args], cwd=config.path, text=True, capture_output=True)
        if cp.returncode != 0:
            return ()
        return tuple(line.strip() for line in cp.stdout.splitlines() if line.strip())

    @classmethod
    def _exclusive_commits(cls, config: RepoConfig) -> tuple[str, ...]:
        return cls._git_lines(config, ["log", "--format=%H", f"{config.base_ref}..HEAD"])

    @classmethod
    def _last_activity(cls, config: RepoConfig) -> str | None:
        lines = cls._git_lines(config, ["log", "-1", "--format=%cI", "HEAD"])
        return lines[0] if lines else None

    @staticmethod
    def _patch_summary(finding: GitFinding) -> str:
        return (
            f"modified={len(finding.modified)} staged={len(finding.staged)} "
            f"untracked={len(finding.untracked)} conflicted={len(finding.conflicted)}"
        )

    def reconcile_repository(self, config: RepoConfig, *, audit_only: bool = True) -> ReconcileReport:
        self.ledger.expire_leases(datetime.now(tz=UTC))
        finding = scan_repository(config.path, config.base_ref)
        existing = self._find_existing(config, finding)
        remote = self._remote(config, finding)
        if existing is None and not self._actionable(finding):
            return self._report(config, finding, None, None, audit_only, remote)

        orphan_unassociated = existing is None or (
            existing.classification is Classification.ORPHAN_UNKNOWN and existing.task_id.startswith("ORPHAN-")
        )
        decision = classify(None if orphan_unassociated else existing, finding, remote)
        if orphan_unassociated and decision.classification is not Classification.ORPHAN_UNKNOWN:
            decision = classify(None, finding, None)

        if existing is None:
            branch = finding.branch or f"DETACHED-{finding.head[:12]}"
            now = datetime.now(tz=UTC)
            existing = TaskRecord(
                task_id=self._stable_orphan_id(config, finding), repository=config.repository, host=config.host,
                worktree_path=finding.repo, branch=branch, base_sha=self._base_sha(config), current_head=finding.head,
                objective="triage discovered repository work without mutating it",
                status=decision.status, classification=decision.classification,
                dirty_files=list(finding.modified), staged_files=list(finding.staged),
                untracked_files=list(finding.untracked), ahead_count=finding.ahead_count,
                behind_count=finding.behind_count, pull_request=remote.pr_url if remote else None,
                ci_state=remote.checks_state if remote else None,
                blocker=remote.evidence_error if remote else None, next_action=decision.next_action,
                priority=decision.priority, patch_summary=self._patch_summary(finding),
                created_at=now, updated_at=now,
            )
            self.ledger.create_task(existing)
        else:
            desired: dict[str, Any] = {
                "current_head": finding.head,
                "dirty_files": list(finding.modified),
                "staged_files": list(finding.staged),
                "untracked_files": list(finding.untracked),
                "ahead_count": finding.ahead_count,
                "behind_count": finding.behind_count,
                "pull_request": remote.pr_url if remote else existing.pull_request,
                "ci_state": remote.checks_state if remote else existing.ci_state,
                "status": decision.status,
                "classification": decision.classification,
                "priority": decision.priority,
                "next_action": decision.next_action,
                "patch_summary": self._patch_summary(finding),
            }
            if remote and remote.evidence_error:
                desired["blocker"] = remote.evidence_error
            changed = {
                key: value for key, value in desired.items()
                if getattr(existing, key) != value
            }
            if changed:
                changed["updated_at"] = datetime.now(tz=UTC)
                existing = self.ledger.update_fields(existing.task_id, **changed)
        return self._report(config, finding, existing, decision, audit_only, remote)

    @staticmethod
    def _report(config: RepoConfig, finding: GitFinding, task: TaskRecord | None, decision: Any | None,
                audit_only: bool, remote: RemoteState | None) -> ReconcileReport:
        changed = tuple(sorted(set(finding.modified) | set(finding.staged) | set(finding.untracked)))
        return ReconcileReport(
            repository=config.repository, host=config.host, path=finding.repo, branch=finding.branch,
            head=finding.head, upstream=finding.upstream, changed_files=changed,
            exclusive_commits=Controller._exclusive_commits(config),
            last_activity=Controller._last_activity(config),
            staged_files=finding.staged, untracked_files=finding.untracked, stash_count=finding.stash_count,
            ahead_count=finding.ahead_count, behind_count=finding.behind_count,
            operation_in_progress=finding.operation_in_progress,
            task_id=task.task_id if task else None,
            classification=(decision.classification.value if decision else (task.classification.value if task else None)),
            risk_priority=(decision.priority if decision else (task.priority if task else None)),
            next_action=(decision.next_action if decision else (task.next_action if task else None)),
            pull_request=remote.pr_url if remote else (task.pull_request if task else None),
            ci_state=remote.checks_state if remote else (task.ci_state if task else None),
            audit_only=audit_only,
        )
