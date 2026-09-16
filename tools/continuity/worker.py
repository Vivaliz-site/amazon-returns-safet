from __future__ import annotations

import argparse
from dataclasses import dataclass, field
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import signal
import sqlite3
import subprocess
from typing import Callable, Mapping

from .git_scan import scan_repository
from .job_queue import (
    JobEnvelope,
    JobValidationError,
    QueuePaths,
    WorkerReceipt,
    atomic_write_receipt,
    claim_next_pending,
    load_job,
    validate_job,
)
from .resume_packet import redact
from .worktrees import WorktreeSafetyError, assert_worker_owned_worktree

UTC = timezone.utc

@dataclass(frozen=True)
class ProcessResult:
    returncode: int
    stdout: str
    stderr: str
    timed_out: bool = False


@dataclass(frozen=True)
class WorkerConfig:
    ledger_path: Path
    queue_paths: QueuePaths
    worker_root: Path
    worker_uid: int
    gemini_bin: str
    model: str
    policy_path: Path
    timeout_seconds: int = 900
    base_env: Mapping[str, str] = field(default_factory=dict)


@dataclass(frozen=True)
class WorkerRunResult:
    classification: str
    receipt_path: Path
    task_id: str
    lease_session_id: str


Runner = Callable[..., ProcessResult]

_ALLOWED_ENV = frozenset({
    "PATH", "HOME", "LANG", "LC_ALL", "TERM", "TMPDIR", "NO_COLOR",
    "GEMINI_API_KEY", "GOOGLE_API_KEY", "GEMINI_MODEL",
})


def build_worker_env(source: Mapping[str, str]) -> dict[str, str]:
    env = {key: str(value) for key, value in source.items() if key in _ALLOWED_ENV and str(value) != ""}
    env.setdefault("NO_COLOR", "1")
    return env


def run_process(
    argv: list[str], *, cwd: Path, input_text: str,
    env: Mapping[str, str], timeout_seconds: int,
) -> ProcessResult:
    proc = subprocess.Popen(
        argv, cwd=cwd, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True, start_new_session=True,
        env=dict(env), close_fds=True,
    )
    try:
        stdout, stderr = proc.communicate(input=input_text, timeout=timeout_seconds)
        return ProcessResult(int(proc.returncode or 0), stdout or "", stderr or "", False)
    except subprocess.TimeoutExpired:
        os.killpg(proc.pid, signal.SIGTERM)
        try:
            stdout, stderr = proc.communicate(timeout=2)
        except subprocess.TimeoutExpired:
            os.killpg(proc.pid, signal.SIGKILL)
            stdout, stderr = proc.communicate()
        return ProcessResult(int(proc.returncode or -15), stdout or "", stderr or "", True)


def _read_task_evidence(ledger_path: Path, task_id: str) -> dict[str, object] | None:
    uri = f"file:{Path(ledger_path).resolve()}?mode=ro"
    conn = sqlite3.connect(uri, uri=True, timeout=5)
    conn.row_factory = sqlite3.Row
    try:
        row = conn.execute(
            """SELECT task_id,repository,worktree_path,branch,current_head,agent_type,
                      agent_session_id,lease_expires_at,dirty_files,staged_files,untracked_files
               FROM tasks WHERE task_id=?""",
            (task_id,),
        ).fetchone()
    finally:
        conn.close()
    if row is None:
        return None
    data = dict(row)
    for key in ("dirty_files", "staged_files", "untracked_files"):
        try:
            data[key] = set(json.loads(str(data.get(key) or "[]")))
        except json.JSONDecodeError:
            data[key] = set()
    return data


def _parse_dt(raw: object) -> datetime | None:
    if not raw:
        return None
    try:
        value = datetime.fromisoformat(str(raw))
    except ValueError:
        return None
    if value.tzinfo is None:
        value = value.replace(tzinfo=UTC)
    return value.astimezone(UTC)


def _oldest_pending(paths: QueuePaths) -> Path | None:
    files = [path for path in paths.pending.glob("*.json") if path.is_file()]
    if not files:
        return None
    return min(files, key=lambda path: (path.stat().st_mtime_ns, path.name))


def _load_packet(job: JobEnvelope, paths: QueuePaths) -> tuple[str | None, str | None]:
    try:
        packet_path = Path(job.resume_packet_path).resolve(strict=True)
        packet_root = (paths.root / "packets").resolve(strict=True)
        packet_path.relative_to(packet_root)
    except (OSError, ValueError):
        return None, "resume packet outside queue"
    try:
        data = packet_path.read_bytes()
    except OSError:
        return None, "resume packet unreadable"
    digest = hashlib.sha256(data).hexdigest()
    if digest != job.resume_packet_sha256:
        return None, "resume packet digest mismatch"
    return data.decode("utf-8", errors="replace"), None

def _find_int(payload: object, names: tuple[str, ...]) -> int | None:
    if isinstance(payload, dict):
        for name in names:
            value = payload.get(name)
            if isinstance(value, int) and value >= 0:
                return value
        for value in payload.values():
            found = _find_int(value, names)
            if found is not None:
                return found
    elif isinstance(payload, list):
        for value in payload:
            found = _find_int(value, names)
            if found is not None:
                return found
    return None


def _token_usage(stdout: str) -> tuple[int | None, int | None, int | None]:
    try:
        payload = json.loads(stdout or "{}")
    except json.JSONDecodeError:
        return None, None, None
    input_tokens = _find_int(payload, ("input_tokens", "inputTokens", "prompt_tokens", "promptTokenCount"))
    output_tokens = _find_int(payload, ("output_tokens", "outputTokens", "completion_tokens", "candidatesTokenCount"))
    cached_tokens = _find_int(payload, ("cached_tokens", "cachedTokens", "cachedContentTokenCount"))
    return input_tokens, output_tokens, cached_tokens


def _diagnostics(classification: str, stdout: str = "", stderr: str = "", reason: str = "") -> str:
    parts = [classification]
    if reason:
        parts.append(reason)
    if stderr:
        parts.append(stderr)
    elif stdout and classification != "completed":
        parts.append(stdout)
    return redact(" | ".join(parts))[:4096]

def _emit_receipt(
    config: WorkerConfig, job: JobEnvelope, *, classification: str, status: str,
    started_at: datetime, ended_at: datetime, resulting_head: str = "",
    changed_paths: tuple[str, ...] = (), validations: tuple[str, ...] = (),
    process: ProcessResult | None = None, reason: str = "",
) -> WorkerRunResult:
    input_tokens = output_tokens = cached_tokens = None
    if process is not None:
        input_tokens, output_tokens, cached_tokens = _token_usage(process.stdout)
    receipt = WorkerReceipt(
        task_id=job.task_id, lease_session_id=job.lease_session_id,
        provider=job.provider, status=status, resulting_head=resulting_head,
        changed_paths=changed_paths, validations=validations,
        diagnostics=_diagnostics(
            classification,
            process.stdout if process else "",
            process.stderr if process else "",
            reason,
        ),
        started_at=started_at.astimezone(UTC).isoformat(),
        ended_at=ended_at.astimezone(UTC).isoformat(),
        model=config.model, input_tokens=input_tokens,
        output_tokens=output_tokens, cached_tokens=cached_tokens,
    )
    path = atomic_write_receipt(config.queue_paths, receipt)
    return WorkerRunResult(classification, path, job.task_id, job.lease_session_id)

def run_one_job(
    config: WorkerConfig, *, now: datetime | None = None,
    runner: Runner = run_process,
) -> WorkerRunResult:
    current = (now or datetime.now(tz=UTC)).astimezone(UTC)
    running = claim_next_pending(config.queue_paths)
    if running is None:
        return WorkerRunResult("idle", Path(), "", "")
    started = current
    job = load_job(running)

    try:
        worktree = validate_job(job, root=config.worker_root, now=current)
    except JobValidationError as exc:
        return _emit_receipt(
            config, job, classification="rejected_job", status="rejected",
            started_at=started, ended_at=current, reason=str(exc),
        )
    if job.provider.lower() != "gemini":
        return _emit_receipt(
            config, job, classification="rejected_provider", status="rejected",
            started_at=started, ended_at=current, reason="provider is not certified",
        )
    try:
        assert_worker_owned_worktree(worktree, config.worker_root, config.worker_uid)
    except WorktreeSafetyError as exc:
        return _emit_receipt(
            config, job, classification="rejected_worktree", status="rejected",
            started_at=started, ended_at=current, reason=str(exc),
        )
    task = _read_task_evidence(config.ledger_path, job.task_id)
    if task is None:
        return _emit_receipt(
            config, job, classification="rejected_lease", status="rejected",
            started_at=started, ended_at=current, reason="task missing from ledger",
        )
    lease_expiry = _parse_dt(task.get("lease_expires_at"))
    if (
        task.get("agent_session_id") != job.lease_session_id
        or str(task.get("agent_type") or "").lower() != "gemini"
        or lease_expiry is None or lease_expiry <= current
    ):
        return _emit_receipt(
            config, job, classification="rejected_lease", status="rejected",
            started_at=started, ended_at=current, reason="lease/session mismatch or expiry",
        )
    identity_ok = (
        task.get("repository") == job.repository
        and Path(str(task.get("worktree_path"))).resolve() == worktree
        and task.get("branch") == job.branch
    )
    if not identity_ok:
        return _emit_receipt(
            config, job, classification="rejected_identity", status="rejected",
            started_at=started, ended_at=current, reason="ledger identity mismatch",
        )

    finding = scan_repository(worktree)
    if finding.head != job.expected_head or str(task.get("current_head")) != job.expected_head:
        return _emit_receipt(
            config, job, classification="rejected_head_mismatch", status="rejected",
            started_at=started, ended_at=current, resulting_head=finding.head,
            reason="expected head differs from ledger/worktree",
        )
    if finding.branch != job.branch:
        return _emit_receipt(
            config, job, classification="rejected_identity", status="rejected",
            started_at=started, ended_at=current, resulting_head=finding.head,
            reason="worktree branch mismatch",
        )
    if finding.operation_in_progress or finding.conflicted or finding.lock_files:
        return _emit_receipt(
            config, job, classification="rejected_operation", status="rejected",
            started_at=started, ended_at=current, resulting_head=finding.head,
            reason="git operation/conflict/lock already in progress",
        )
    expected_dirty = set(task.get("dirty_files") or set())
    expected_staged = set(task.get("staged_files") or set())
    expected_untracked = set(task.get("untracked_files") or set())
    if (
        not set(finding.modified).issubset(expected_dirty)
        or not set(finding.staged).issubset(expected_staged)
        or not set(finding.untracked).issubset(expected_untracked)
    ):
        return _emit_receipt(
            config, job, classification="rejected_unexpected_worktree_state", status="rejected",
            started_at=started, ended_at=current, resulting_head=finding.head,
            reason="worktree evidence exceeds ledger snapshot",
        )

    packet, packet_error = _load_packet(job, config.queue_paths)
    if packet_error is not None or packet is None:
        classification = "rejected_packet_digest" if "digest" in str(packet_error) else "rejected_packet"
        return _emit_receipt(
            config, job, classification=classification, status="rejected",
            started_at=started, ended_at=current, resulting_head=finding.head,
            reason=str(packet_error),
        )
    if not config.policy_path.is_file():
        return _emit_receipt(
            config, job, classification="rejected_policy", status="rejected",
            started_at=started, ended_at=current, resulting_head=finding.head,
            reason="Gemini admin policy unavailable",
        )
    worker_env = build_worker_env(config.base_env)
    gemini_dir = str(Path(config.gemini_bin).parent)
    existing_path = worker_env.get("PATH", "/usr/local/bin:/usr/bin:/bin")
    path_parts = [part for part in existing_path.split(os.pathsep) if part and part != gemini_dir]
    worker_env["PATH"] = os.pathsep.join([gemini_dir, *path_parts])
    if not (worker_env.get("GEMINI_API_KEY") or worker_env.get("GOOGLE_API_KEY")):
        return _emit_receipt(
            config, job, classification="rejected_credentials", status="rejected",
            started_at=started, ended_at=current, resulting_head=finding.head,
            reason="Gemini credential unavailable",
        )
    argv = [
        config.gemini_bin,
        "--model", config.model,
        "--output-format", "json",
        "--approval-mode", "yolo",
        "--admin-policy", str(config.policy_path),
        "--skip-trust",
        "--prompt", "",
    ]
    process = runner(
        argv, cwd=worktree, input_text=packet,
        env=worker_env, timeout_seconds=config.timeout_seconds,
    )
    ended = current
    after = scan_repository(worktree)
    changed_paths = tuple(sorted(set(after.modified) | set(after.staged) | set(after.untracked)))
    if process.timed_out:
        classification, status = "timeout", "timeout"
    elif process.returncode != 0:
        classification, status = "provider_failed", "failed"
    else:
        classification, status = "completed", "completed"
    validations = (
        f"gemini_exit:{process.returncode}",
        f"head_before:{finding.head}",
        f"head_after:{after.head}",
    )
    return _emit_receipt(
        config, job, classification=classification, status=status,
        started_at=started, ended_at=ended, resulting_head=after.head,
        changed_paths=changed_paths, validations=validations,
        process=process,
    )


def _config_from_json(path: Path) -> WorkerConfig:
    payload = json.loads(path.read_text(encoding="utf-8"))
    queue_paths = QueuePaths.under(Path(str(payload["job_queue_root"])))
    return WorkerConfig(
        ledger_path=Path(str(payload["ledger_path"])),
        queue_paths=queue_paths,
        worker_root=Path(str(payload["worker_root"])),
        worker_uid=int(payload.get("worker_uid", os.getuid())),
        gemini_bin=str(payload.get("gemini_bin", "gemini")),
        model=str(payload.get("gemini_model", os.environ.get("GEMINI_MODEL", "gemini-3.1-flash-lite"))),
        policy_path=Path(str(payload.get(
            "gemini_admin_policy",
            "/opt/agent-continuity/current/deploy/continuity/gemini-admin-policy.toml",
        ))),
        timeout_seconds=int(payload.get("worker_timeout_seconds", 900)),
        base_env=dict(os.environ),
    )

def _print_run_result(result: WorkerRunResult) -> None:
    print(json.dumps({
        "classification": result.classification,
        "task_id": result.task_id,
        "lease_session_id": result.lease_session_id,
        "receipt_path": str(result.receipt_path) if result.receipt_path else "",
    }, sort_keys=True))


def _result_failed(result: WorkerRunResult) -> bool:
    return not (
        result.classification in {"idle", "completed"}
        or result.classification.startswith("rejected_")
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="agent-continuity-worker")
    parser.add_argument("--config", required=True)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--once", action="store_true")
    mode.add_argument("--drain", action="store_true")
    args = parser.parse_args(argv)
    config = _config_from_json(Path(args.config))
    if args.once:
        result = run_one_job(config)
        _print_run_result(result)
        return 1 if _result_failed(result) else 0

    saw_failure = False
    while True:
        result = run_one_job(config)
        _print_run_result(result)
        if result.classification == "idle":
            return 1 if saw_failure else 0
        saw_failure = saw_failure or _result_failed(result)


if __name__ == "__main__":
    raise SystemExit(main())
