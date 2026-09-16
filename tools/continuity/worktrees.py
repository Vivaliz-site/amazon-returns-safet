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
    cp = subprocess.run(["git", "-c", f"safe.directory={repo.resolve()}", *args], cwd=repo, text=True, capture_output=True)
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
        ["git", "-c", f"safe.directory={target.resolve()}", "rev-parse", "HEAD"], cwd=target, text=True, capture_output=True, check=True
    ).stdout.strip()
    return WorktreeResult(path=target, branch=branch, base_ref=base_ref, head=head)


def _reject_symlink_components(path: Path) -> None:
    absolute = Path(path).absolute()
    current = Path(absolute.anchor)
    for part in absolute.parts[1:]:
        current = current / part
        if current.exists() and current.is_symlink():
            raise WorktreeSafetyError(f"symlink component is not allowed: {current}")


def _repository_slug(repository: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9._-]+/[A-Za-z0-9._-]+", repository):
        raise WorktreeSafetyError("unsafe repository name")
    return repository.replace("/", "-").lower()


def _git_dir(source: Path, args: list[str], *, check: bool = True) -> subprocess.CompletedProcess[str]:
    cp = subprocess.run(["git", "--git-dir", str(source), *args], text=True, capture_output=True)
    if check and cp.returncode != 0:
        raise WorktreeSafetyError(f"git {' '.join(args)} failed: {cp.stderr.strip()}")
    return cp

def prepare_worker_source(repo_url: str, source_root: Path, repository: str) -> Path:
    source_root = Path(source_root)
    _reject_symlink_components(source_root)
    source_root.mkdir(parents=True, exist_ok=True)
    target = source_root / f"{_repository_slug(repository)}.git"
    if target.exists():
        _reject_symlink_components(target)
        if _git_dir(target, ["rev-parse", "--is-bare-repository"]).stdout.strip() != "true":
            raise WorktreeSafetyError("worker source is not bare")
    else:
        cp = subprocess.run(["git", "clone", "--bare", repo_url, str(target)], text=True, capture_output=True)
        if cp.returncode != 0:
            raise WorktreeSafetyError(f"git clone --bare failed: {cp.stderr.strip()}")
    _git_dir(target, ["config", "remote.origin.fetch", "+refs/heads/*:refs/remotes/origin/*"])
    _git_dir(target, ["fetch", "--prune", "origin"])
    return target


def assert_worker_owned_worktree(path: Path, root: Path, worker_uid: int) -> None:
    path = Path(path)
    root = Path(root)
    _reject_symlink_components(root)
    _reject_symlink_components(path)
    try:
        worker_root = root.resolve(strict=True)
        resolved = path.resolve(strict=True)
        resolved.relative_to(worker_root)
    except (OSError, ValueError) as exc:
        raise WorktreeSafetyError("worktree outside worker root") from exc
    if resolved == worker_root:
        raise WorktreeSafetyError("worker root itself is not a task worktree")
    if resolved.stat().st_uid != int(worker_uid):
        raise WorktreeSafetyError("worktree owner mismatch")

def create_worker_task_worktree(
    source: Path,
    worktree_root: Path,
    task_id: str,
    slug: str,
    base_ref: str,
    worker_uid: int,
) -> WorktreeResult:
    source = Path(source).resolve(strict=True)
    if _git_dir(source, ["rev-parse", "--is-bare-repository"]).stdout.strip() != "true":
        raise WorktreeSafetyError("worker source must be bare")
    if not re.fullmatch(r"[A-Za-z0-9._-]+", task_id):
        raise WorktreeSafetyError("unsafe task id")
    branch = f"agent/{task_id}-{_slug(slug)}"
    repo_name = source.name[:-4] if source.name.endswith(".git") else source.name
    target = Path(worktree_root).resolve() / "worktrees" / repo_name / task_id
    _reject_symlink_components(Path(worktree_root))
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists():
        raise WorktreeSafetyError(f"target already exists and will not be reused: {target}")
    if _git_dir(source, ["show-ref", "--verify", "--quiet", f"refs/heads/{branch}"], check=False).returncode == 0:
        raise WorktreeSafetyError(f"branch already exists and will not be reused: {branch}")
    _git_dir(source, ["rev-parse", "--verify", base_ref])
    _git_dir(source, ["worktree", "add", "-b", branch, str(target), base_ref])
    assert_worker_owned_worktree(target, Path(worktree_root).resolve() / "worktrees", worker_uid)
    head = subprocess.run(
        ["git", "-c", f"safe.directory={target}", "rev-parse", "HEAD"],
        cwd=target, text=True, capture_output=True, check=True,
    ).stdout.strip()
    return WorktreeResult(path=target, branch=branch, base_ref=base_ref, head=head)
