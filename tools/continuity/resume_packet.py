from __future__ import annotations

import json
import re
from typing import Iterable

from .git_scan import GitFinding
from .github_state import RemoteState
from .model import TaskRecord, ensure_utc


_PATTERNS: tuple[tuple[re.Pattern[str], str], ...] = (
    (re.compile(r"-----BEGIN [^-\n]{0,80}PRIVATE KEY-----.*?-----END [^-\n]{0,80}PRIVATE KEY-----", re.DOTALL), "[REDACTED_PRIVATE_KEY]"),
    (re.compile("(?i)" + "otpauth" + r"://[^\s]+"), "[REDACTED_OTP_URI]"),
    (re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9_]{8,}|github_pat_[A-Za-z0-9_]{8,})\b"), "[REDACTED]"),
    (re.compile(r"\bsk-[A-Za-z0-9_-]{8,}\b"), "[REDACTED]"),
    (re.compile(r"(?i)(authorization\s*:\s*bearer\s+)[A-Za-z0-9._~+/-]{8,}"), r"\1[REDACTED]"),
    (re.compile(r"(?im)^(cookie|set-cookie)\s*:\s*.*$"), r"\1: [REDACTED]"),
    (re.compile(r"(?i)\b(password|passwd|pwd|secret|token)\s*[:=]\s*[^\s,;]+"), r"\1=[REDACTED]"),
)


def redact(text: str) -> str:
    result = str(text)
    for pattern, replacement in _PATTERNS:
        result = pattern.sub(replacement, result)
    return result


def _bounded(value: object | None, limit: int = 500) -> str:
    if value is None or value == "":
        return "none"
    text = str(value).replace("\r", " ")
    if len(text) > limit:
        text = text[:limit] + "...[truncated]"
    return text


def _items(values: Iterable[object], limit: int = 100) -> str:
    safe = sorted(_bounded(value, 300) for value in list(values)[:limit])
    return json.dumps(safe, ensure_ascii=True, separators=(",", ":"))


def render_resume_packet(task: TaskRecord, finding: GitFinding, remote: RemoteState | None) -> str:
    last_heartbeat = ensure_utc(task.last_heartbeat_at)
    changed = sorted(set(finding.modified) | set(task.dirty_files))
    staged = sorted(set(finding.staged) | set(task.staged_files))
    untracked = sorted(set(finding.untracked) | set(task.untracked_files))
    pr_state = "none"
    if remote is not None:
        parts = [remote.pr_state or "none"]
        if remote.pr_number is not None:
            parts.append(f"#{remote.pr_number}")
        if remote.pr_url:
            parts.append(remote.pr_url)
        pr_state = " ".join(parts)
    blockers = [value for value in (task.blocker, remote.evidence_error if remote else None) if value]
    lines = [
        f"TASK_ID: {_bounded(task.task_id)}",
        f"repository: {_bounded(task.repository)}",
        f"host: {_bounded(task.host)}",
        f"worktree_path: {_bounded(task.worktree_path)}",
        f"branch: {_bounded(task.branch)}",
        f"base_sha: {_bounded(task.base_sha)}",
        f"current_head: {_bounded(task.current_head)}",
        f"objective: {_bounded(task.objective)}",
        f"status: {task.status.value}",
        f"classification: {task.classification.value}",
        f"last_agent: {_bounded(task.agent_type)}",
        f"last_heartbeat: {_bounded(last_heartbeat.isoformat() if last_heartbeat else None)}",
        f"commits_since_base: {finding.ahead_count}",
        f"changed_files: {_items(changed)}",
        f"untracked_files: {_items(untracked)}",
        f"staged_files: {_items(staged)}",
        f"patch_summary: {_bounded(task.patch_summary)}",
        f"operation_in_progress: {_items(finding.operation_in_progress)}",
        f"local_test_results: {_items(task.local_test_results)}",
        f"CI_state: {_bounded(task.ci_state or (remote.checks_state if remote else None))}",
        f"PR_state: {_bounded(pr_state)}",
        f"known_failures: {_items(task.known_failures)}",
        f"blockers: {_items(blockers)}",
        f"next_action: {_bounded(task.next_action)}",
        "safety_constraints: preserve existing worktree/branch; one active lease; no destructive git; no secrets in logs or commits",
        "completion_criteria: commit -> push -> PR/checks -> merge -> post-merge verification -> DONE",
        "",
        "Continue exatamente desta worktree e branch.",
        "Nao recrie a implementacao do zero.",
        "Nao descarte nem sobrescreva alteracoes existentes.",
        "Valide antes de commit/push.",
        "Conclua commit -> push -> PR -> checks -> merge -> verificacao.",
        "Registre checkpoint seguro antes de encerrar.",
        "",
    ]
    return redact("\n".join(lines))
