from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
import re
import subprocess


class WorktreeSafetyError(RuntimeError):
    pass


@dataclass(frozen=True)
class WorktreeResult:
    path: Path
    branch: str
    base_ref: str
    head: str


def _slug(value: str) -> str:
    clean = re.sub(r"[^a-zA-Z0-9._-]+", "-", value.strip()).strip("-.").lower()
    if not clean:
        raise WorktreeSafetyError("slug must contain a safe character")
    return clean[:60]


def _run(repo: Path, args: list[str], *, check: bool = True) -> subprocess.CompletedProcess[str]:
    cp = subprocess.run(["git", *args], cwd=repo, text=True, capture_output=True)
    if check and cp.returncode != 0:
        raise WorktreeSafetyError(f"git {' '.join(args)} failed: {cp.stderr.strip()}")
    return cp


def create_task_worktree(repo: Path, root: Path, task_id: str, slug: str, base_ref: str) -> WorktreeResult:
    repo = Path(repo).resolve()
    root = Path(root).resolve()
    if not repo.exists():
        raise WorktreeSafetyError(f"repository does not exist: {repo}")
    if (repo / ".release-sha").exists():
        raise WorktreeSafetyError("refusing to use a deploy checkout as an agent source")
    if not re.fullmatch(r"[A-Za-z0-9._-]+", task_id):
        raise WorktreeSafetyError("unsafe task id")
    branch = f"agent/{task_id}-{_slug(slug)}"
    target = root / "worktrees" / repo.name / task_id
    if target.exists():
        raise WorktreeSafetyError(f"target already exists and will not be reused: {target}")
    if _run(repo, ["show-ref", "--verify", "--quiet", f"refs/heads/{branch}"], check=False).returncode == 0:
        raise WorktreeSafetyError(f"branch already exists and will not be reused: {branch}")
    _run(repo, ["rev-parse", "--verify", base_ref])
    target.parent.mkdir(parents=True, exist_ok=True)
    _run(repo, ["worktree", "add", "-b", branch, str(target), base_ref])
    head = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=target, text=True, capture_output=True, check=True
    ).stdout.strip()
    return WorktreeResult(path=target, branch=branch, base_ref=base_ref, head=head)
