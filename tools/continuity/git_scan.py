from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
import subprocess
from typing import Sequence


@dataclass(frozen=True)
class GitFinding:
    repo: str
    branch: str | None
    head: str
    upstream: str | None
    modified: tuple[str, ...] = field(default_factory=tuple)
    staged: tuple[str, ...] = field(default_factory=tuple)
    untracked: tuple[str, ...] = field(default_factory=tuple)
    stash_count: int = 0
    ahead_count: int = 0
    behind_count: int = 0
    detached: bool = False
    operation_in_progress: tuple[str, ...] = field(default_factory=tuple)
    conflicted: tuple[str, ...] = field(default_factory=tuple)
    worktrees: tuple[str, ...] = field(default_factory=tuple)
    lock_files: tuple[str, ...] = field(default_factory=tuple)

    @property
    def dirty(self) -> bool:
        return bool(self.modified or self.staged or self.untracked or self.conflicted)


class GitScanError(RuntimeError):
    pass


def _git_command(repo: Path, args: Sequence[str]) -> list[str]:
    return ["git", "-c", f"safe.directory={Path(repo).resolve()}", *args]


def _run(repo: Path, args: Sequence[str], *, check: bool = True) -> subprocess.CompletedProcess[str]:
    cp = subprocess.run(
        _git_command(repo, args),
        cwd=repo,
        text=True,
        capture_output=True,
    )
    if check and cp.returncode != 0:
        raise GitScanError(f"git {' '.join(args)} failed: {cp.stderr.strip()}")
    return cp


def _git_path(repo: Path, marker: str) -> Path:
    raw = _run(repo, ["rev-parse", "--git-path", marker]).stdout.strip()
    path = Path(raw)
    return path if path.is_absolute() else repo / path


def _status(repo: Path) -> tuple[str | None, str, str | None, set[str], set[str], set[str], set[str]]:
    cp = _run(repo, ["status", "--porcelain=v2", "--branch", "-z"], check=False)
    if cp.returncode != 0:
        raise GitScanError(f"git status failed: {cp.stderr.strip()}")
    branch: str | None = None
    head = ""
    upstream: str | None = None
    modified: set[str] = set()
    staged: set[str] = set()
    untracked: set[str] = set()
    conflicted: set[str] = set()
    for rec in cp.stdout.split("\0"):
        if not rec:
            continue
        if rec.startswith("# branch.oid "):
            head = rec.removeprefix("# branch.oid ").strip()
        elif rec.startswith("# branch.head "):
            value = rec.removeprefix("# branch.head ").strip()
            branch = None if value == "(detached)" else value
        elif rec.startswith("# branch.upstream "):
            upstream = rec.removeprefix("# branch.upstream ").strip() or None
        elif rec.startswith("? "):
            untracked.add(rec[2:])
        elif rec.startswith("u "):
            path = rec.split(" ", 10)[-1]
            conflicted.add(path)
            modified.add(path)
            staged.add(path)
        elif rec.startswith("1 "):
            parts = rec.split(" ", 8)
            if len(parts) != 9:
                continue
            xy = parts[1]
            path = parts[8]
            if xy[0] not in (".", " "):
                staged.add(path)
            if xy[1] not in (".", " "):
                modified.add(path)
        elif rec.startswith("2 "):
            parts = rec.split(" ", 9)
            if len(parts) != 10:
                continue
            xy = parts[1]
            path = parts[9]
            if xy[0] not in (".", " "):
                staged.add(path)
            if xy[1] not in (".", " "):
                modified.add(path)
    if not head or head == "(initial)":
        head = _run(repo, ["rev-parse", "HEAD"], check=False).stdout.strip()
    return branch, head, upstream, modified, staged, untracked, conflicted


def _ahead_behind(repo: Path, base_ref: str) -> tuple[int, int]:
    cp = _run(repo, ["rev-list", "--left-right", "--count", f"{base_ref}...HEAD"], check=False)
    if cp.returncode != 0:
        return 0, 0
    parts = cp.stdout.strip().replace("\t", " ").split()
    if len(parts) != 2:
        return 0, 0
    behind, ahead = (int(parts[0]), int(parts[1]))
    return ahead, behind


def _worktrees(repo: Path) -> tuple[str, ...]:
    cp = _run(repo, ["worktree", "list", "--porcelain"])
    return tuple(
        line.removeprefix("worktree ")
        for line in cp.stdout.splitlines()
        if line.startswith("worktree ")
    )


def _locks(repo: Path) -> tuple[str, ...]:
    git_dir_raw = _run(repo, ["rev-parse", "--absolute-git-dir"]).stdout.strip()
    git_dir = Path(git_dir_raw)
    candidates = list(git_dir.glob("*.lock")) + list((git_dir / "refs").glob("**/*.lock"))
    return tuple(sorted(str(path) for path in candidates if path.exists()))


def scan_repository(repo: Path, base_ref: str = "origin/main") -> GitFinding:
    repo = Path(repo).resolve()
    if not repo.exists():
        raise GitScanError(f"repository does not exist: {repo}")
    branch, head, upstream, modified, staged, untracked, conflicted = _status(repo)
    stash_count = len([line for line in _run(repo, ["stash", "list"]).stdout.splitlines() if line.strip()])
    ahead, behind = _ahead_behind(repo, base_ref)
    markers = ("MERGE_HEAD", "rebase-merge", "rebase-apply", "CHERRY_PICK_HEAD", "REVERT_HEAD", "BISECT_LOG")
    active_markers = tuple(marker for marker in markers if _git_path(repo, marker).exists())
    return GitFinding(
        repo=str(repo),
        branch=branch,
        head=head,
        upstream=upstream,
        modified=tuple(sorted(modified)),
        staged=tuple(sorted(staged)),
        untracked=tuple(sorted(untracked)),
        stash_count=stash_count,
        ahead_count=ahead,
        behind_count=behind,
        detached=branch is None,
        operation_in_progress=active_markers,
        conflicted=tuple(sorted(conflicted)),
        worktrees=_worktrees(repo),
        lock_files=_locks(repo),
    )
