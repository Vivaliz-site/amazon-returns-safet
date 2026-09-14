from __future__ import annotations

from dataclasses import dataclass
import json
import re
import subprocess
from typing import Callable, Sequence
from urllib.parse import quote


Runner = Callable[[Sequence[str]], subprocess.CompletedProcess[str]]


@dataclass(frozen=True)
class RemoteState:
    repository: str
    branch: str
    base: str
    branch_present: bool = False
    head_sha: str | None = None
    pr_number: int | None = None
    pr_url: str | None = None
    pr_state: str | None = None
    merged: bool = False
    closed_unmerged: bool = False
    checks_state: str | None = None
    mergeable: bool | None = None
    evidence_error: str | None = None


_SECRET_PATTERNS = (
    re.compile(r"\b(?:gh[pousr]_[A-Za-z0-9_]{8,}|github_pat_[A-Za-z0-9_]{8,})\b"),
    re.compile(r"\bsk-[A-Za-z0-9_-]{8,}\b"),
    re.compile(r"(?i)(bearer\s+)[A-Za-z0-9._~+/-]{8,}"),
)


def _redact(text: str) -> str:
    result = text
    for pattern in _SECRET_PATTERNS:
        if pattern.groups:
            result = pattern.sub(lambda m: m.group(1) + "[REDACTED]", result)
        else:
            result = pattern.sub("[REDACTED]", result)
    return result[:2000]


def _default_runner(args: Sequence[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(list(args), text=True, capture_output=True)


def _checks_state(checks: list[dict]) -> str | None:
    if not checks:
        return None
    failure = {"FAILURE", "CANCELLED", "TIMED_OUT", "ACTION_REQUIRED", "STARTUP_FAILURE", "STALE"}
    success = {"SUCCESS", "NEUTRAL", "SKIPPED"}
    saw_pending = False
    for check in checks:
        conclusion = str(check.get("conclusion") or "").upper()
        status = str(check.get("status") or "").upper()
        if conclusion in failure:
            return "failure"
        if status != "COMPLETED" or not conclusion:
            saw_pending = True
        elif conclusion not in success:
            saw_pending = True
    return "pending" if saw_pending else "success"


class GitHubStateReader:
    def __init__(self, runner: Runner | None = None):
        self.runner = runner or _default_runner

    def branch_state(self, repository: str, branch: str, base: str) -> RemoteState:
        ref_path = f"repos/{repository}/git/ref/heads/{quote(branch, safe='')}"
        branch_cp = self.runner(["gh", "api", ref_path])
        branch_present = branch_cp.returncode == 0
        head_sha: str | None = None
        if branch_present:
            try:
                branch_data = json.loads(branch_cp.stdout or "{}")
                head_sha = branch_data.get("object", {}).get("sha")
            except (json.JSONDecodeError, AttributeError):
                return RemoteState(repository, branch, base, evidence_error="invalid GitHub branch JSON")
        elif "404" not in (branch_cp.stderr or ""):
            detail = _redact((branch_cp.stderr or branch_cp.stdout or "GitHub branch lookup failed").strip())
            return RemoteState(repository, branch, base, evidence_error=detail)

        pr_cp = self.runner([
            "gh", "pr", "list", "--repo", repository, "--head", branch, "--state", "all",
            "--limit", "10", "--json",
            "number,url,state,mergedAt,mergeStateStatus,statusCheckRollup,headRefOid,baseRefName",
        ])
        if pr_cp.returncode != 0:
            detail = _redact((pr_cp.stderr or pr_cp.stdout or "GitHub PR lookup failed").strip())
            return RemoteState(repository, branch, base, branch_present=branch_present,
                               head_sha=head_sha, evidence_error=detail)
        try:
            prs = json.loads(pr_cp.stdout or "[]")
        except json.JSONDecodeError:
            return RemoteState(repository, branch, base, branch_present=branch_present,
                               head_sha=head_sha, evidence_error="invalid GitHub PR JSON")
        if not prs:
            return RemoteState(repository, branch, base, branch_present=branch_present, head_sha=head_sha)

        item = prs[0]
        merged = bool(item.get("mergedAt")) or str(item.get("state") or "").upper() == "MERGED"
        state = "MERGED" if merged else str(item.get("state") or "").upper() or None
        merge_state = str(item.get("mergeStateStatus") or "").upper()
        if merge_state == "DIRTY":
            mergeable: bool | None = False
        elif merge_state in {"", "UNKNOWN"}:
            mergeable = None
        else:
            mergeable = True
        return RemoteState(
            repository=repository,
            branch=branch,
            base=base,
            branch_present=branch_present,
            head_sha=head_sha or item.get("headRefOid"),
            pr_number=item.get("number"),
            pr_url=item.get("url"),
            pr_state=state,
            merged=merged,
            closed_unmerged=state == "CLOSED" and not merged,
            checks_state=_checks_state(item.get("statusCheckRollup") or []),
            mergeable=mergeable,
        )
