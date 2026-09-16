from __future__ import annotations

from dataclasses import dataclass
import json
import re
import subprocess
from typing import Callable, Sequence
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen


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


PublicFetcher = Callable[[str], tuple[int, str]]


def _default_public_fetcher(url: str) -> tuple[int, str]:
    request = Request(url, headers={
        "Accept": "application/vnd.github+json",
        "User-Agent": "shopvivaliz-agent-continuity/1",
        "X-GitHub-Api-Version": "2022-11-28",
    }, method="GET")
    try:
        with urlopen(request, timeout=15) as response:
            return int(response.status), response.read().decode("utf-8", errors="replace")
    except HTTPError as exc:
        return int(exc.code), exc.read().decode("utf-8", errors="replace")
    except URLError as exc:
        return 0, str(exc.reason)


class GitHubStateReader:
    def __init__(self, runner: Runner | None = None, *, public_fetcher: PublicFetcher | None = None):
        self.runner = runner
        self.public_fetcher = public_fetcher if public_fetcher is not None else (_default_public_fetcher if runner is None else None)

    def branch_state(self, repository: str, branch: str, base: str) -> RemoteState:
        if self.public_fetcher is not None:
            return self._branch_state_public(repository, branch, base)
        return self._branch_state_cli(repository, branch, base)

    def _branch_state_public(self, repository: str, branch: str, base: str) -> RemoteState:
        fetch = self.public_fetcher
        assert fetch is not None
        api = f"https://api.github.com/repos/{repository}"
        status, body = fetch(f"{api}/git/ref/heads/{quote(branch, safe='')}")
        branch_present = status == 200
        head_sha = None
        if branch_present:
            try:
                head_sha = json.loads(body or "{}").get("object", {}).get("sha")
            except (json.JSONDecodeError, AttributeError):
                return RemoteState(repository, branch, base, evidence_error="invalid GitHub branch JSON")
        elif status != 404:
            return RemoteState(repository, branch, base, evidence_error=_redact(body or f"GitHub HTTP {status}"))

        owner = repository.split("/", 1)[0]
        query = urlencode({"state": "all", "head": f"{owner}:{branch}", "base": base, "per_page": "10"})
        pr_status, pr_body = fetch(f"{api}/pulls?{query}")
        if pr_status != 200:
            return RemoteState(repository, branch, base, branch_present=branch_present, head_sha=head_sha, evidence_error=_redact(pr_body or f"GitHub HTTP {pr_status}"))
        try:
            prs = json.loads(pr_body or "[]")
        except json.JSONDecodeError:
            return RemoteState(repository, branch, base, branch_present=branch_present, head_sha=head_sha, evidence_error="invalid GitHub PR JSON")
        if not prs:
            return RemoteState(repository, branch, base, branch_present=branch_present, head_sha=head_sha)

        summary = prs[0]
        number = int(summary.get("number"))
        detail_status, detail_body = fetch(f"{api}/pulls/{number}")
        if detail_status != 200:
            return RemoteState(repository, branch, base, branch_present=branch_present, head_sha=head_sha, evidence_error=_redact(detail_body or f"GitHub HTTP {detail_status}"))
        try:
            item = json.loads(detail_body or "{}")
        except json.JSONDecodeError:
            return RemoteState(repository, branch, base, branch_present=branch_present, head_sha=head_sha, evidence_error="invalid GitHub PR detail JSON")
        pr_head = (item.get("head") or {}).get("sha") or (summary.get("head") or {}).get("sha")
        checks = []
        check_sha = head_sha or pr_head
        if check_sha:
            check_status, check_body = fetch(f"{api}/commits/{quote(str(check_sha), safe='')}/check-runs")
            if check_status == 200:
                try:
                    checks = json.loads(check_body or "{}").get("check_runs") or []
                except (json.JSONDecodeError, AttributeError):
                    checks = []
        merged = bool(item.get("merged") or item.get("merged_at"))
        state = "MERGED" if merged else str(item.get("state") or summary.get("state") or "").upper() or None
        return RemoteState(
            repository=repository, branch=branch, base=base, branch_present=branch_present,
            head_sha=head_sha or pr_head, pr_number=number,
            pr_url=item.get("html_url") or summary.get("html_url"), pr_state=state,
            merged=merged, closed_unmerged=state == "CLOSED" and not merged,
            checks_state=_checks_state(checks), mergeable=item.get("mergeable"),
        )

    def _branch_state_cli(self, repository: str, branch: str, base: str) -> RemoteState:
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
