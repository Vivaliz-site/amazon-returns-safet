from __future__ import annotations

from dataclasses import dataclass
import os
from pathlib import Path
import shlex
import subprocess
from typing import Callable, Mapping

from .git_scan import scan_repository
from .job_queue import QueuePaths, WorkerReceipt, load_job
from .worktrees import WorktreeSafetyError, assert_worker_owned_worktree


class PublishSafetyError(RuntimeError):
    pass


@dataclass(frozen=True)
class PublisherConfig:
    queue_paths: QueuePaths
    worker_root: Path
    worker_uid: int
    allowed_repositories: frozenset[str]
    ssh_key_path: Path
    known_hosts_path: Path
    base_env: Mapping[str, str]


@dataclass(frozen=True)
class PublishReceipt:
    task_id: str
    repository: str
    branch: str
    head: str
    status: str


GitRunner = Callable[[Path, list[str], Mapping[str, str]], subprocess.CompletedProcess[str]]


def build_publisher_env(config: PublisherConfig) -> dict[str, str]:
    env: dict[str, str] = {}
    for key in ("PATH", "HOME", "LANG", "LC_ALL", "TMPDIR"):
        value = config.base_env.get(key)
        if value:
            env[key] = str(value)
    key = shlex.quote(str(Path(config.ssh_key_path).resolve()))
    known_hosts = shlex.quote(str(Path(config.known_hosts_path).resolve()))
    env["GIT_TERMINAL_PROMPT"] = "0"
    env["GIT_CONFIG_NOSYSTEM"] = "1"
    env["GIT_SSH_COMMAND"] = (
        f"ssh -i {key} -o IdentitiesOnly=yes -o BatchMode=yes "
        f"-o StrictHostKeyChecking=yes -o UserKnownHostsFile={known_hosts}"
    )
    return env


def _default_runner(cwd: Path, args: list[str], env: Mapping[str, str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["git", "-c", f"safe.directory={Path(cwd).resolve()}", *args],
        cwd=cwd, env=dict(env), text=True, capture_output=True, check=False,
    )


def _load_linked_job(receipt: WorkerReceipt, config: PublisherConfig):
    name = f"{receipt.task_id}--{receipt.lease_session_id}.json"
    path = config.queue_paths.running / name
    if not path.exists():
        raise PublishSafetyError("linked running job is missing")
    try:
        return load_job(path)
    except Exception as exc:
        raise PublishSafetyError("linked job is invalid") from exc


def _verify_publishable(receipt: WorkerReceipt, config: PublisherConfig):
    if receipt.status != "completed":
        raise PublishSafetyError("worker receipt is not completed")
    job = _load_linked_job(receipt, config)
    if job.repository not in config.allowed_repositories:
        raise PublishSafetyError("repository is outside publisher allowlist")
    if job.provider.lower() != receipt.provider.lower():
        raise PublishSafetyError("worker provider does not match job")
    if not job.branch.startswith("agent/"):
        raise PublishSafetyError("branch is outside automatic publication prefix")
    worktree = Path(job.worktree_path)
    try:
        assert_worker_owned_worktree(worktree, config.worker_root, config.worker_uid)
    except WorktreeSafetyError as exc:
        raise PublishSafetyError(str(exc)) from exc
    finding = scan_repository(worktree)
    if finding.branch != job.branch:
        raise PublishSafetyError("worktree branch does not match job")
    if finding.head != receipt.resulting_head:
        raise PublishSafetyError("receipt head does not match worktree head")
    if finding.operation_in_progress or finding.conflicted:
        raise PublishSafetyError("worktree has an active git operation")
    if finding.modified or finding.staged or finding.untracked:
        raise PublishSafetyError("worktree is dirty after worker completion")
    if not config.ssh_key_path.is_file() or not config.known_hosts_path.is_file():
        raise PublishSafetyError("publisher ssh material is unavailable")
    return job, worktree


def _remote_head(output: str) -> str | None:
    line = next((line for line in output.splitlines() if line.strip()), "")
    if not line:
        return None
    return line.split()[0] if line.split() else None

def publish_receipt(
    receipt: WorkerReceipt,
    config: PublisherConfig,
    *,
    runner: GitRunner | None = None,
) -> PublishReceipt:
    job, worktree = _verify_publishable(receipt, config)
    git_runner = runner or _default_runner
    env = build_publisher_env(config)
    ref = f"refs/heads/{job.branch}"
    remote = git_runner(worktree, ["ls-remote", "--heads", "origin", ref], env)
    if remote.returncode != 0:
        raise PublishSafetyError("remote branch lookup failed")
    existing = _remote_head(remote.stdout)
    if existing is not None and existing != receipt.resulting_head:
        raise PublishSafetyError("remote branch exists at a different head")
    if existing == receipt.resulting_head:
        return PublishReceipt(receipt.task_id, job.repository, job.branch, receipt.resulting_head, "already_published")
    pushed = git_runner(
        worktree,
        ["push", "origin", f"HEAD:refs/heads/{job.branch}"],
        env,
    )
    if pushed.returncode != 0:
        raise PublishSafetyError("branch push failed")
    return PublishReceipt(receipt.task_id, job.repository, job.branch, receipt.resulting_head, "pushed")
