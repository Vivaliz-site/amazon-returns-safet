from __future__ import annotations

from dataclasses import asdict, dataclass, fields
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
from typing import Any

UTC = timezone.utc
_ALLOWED_PROVIDERS = frozenset(("gemini", "rooter"))
_ID_RE = re.compile(r"^[A-Za-z0-9._:-]{8,160}$")
_TASK_RE = re.compile(r"^[A-Za-z0-9._-]{8,96}$")
_REPO_RE = re.compile(r"^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$")
_SHA40_RE = re.compile(r"^[0-9a-fA-F]{40}$")
_SHA256_RE = re.compile(r"^[0-9a-fA-F]{64}$")


class JobValidationError(ValueError):
    pass


def _parse_time(raw: str) -> datetime:
    try:
        value = datetime.fromisoformat(raw)
    except (TypeError, ValueError) as exc:
        raise JobValidationError("invalid timestamp") from exc
    if value.tzinfo is None:
        raise JobValidationError("timestamp must be timezone-aware")
    return value.astimezone(UTC)

@dataclass(frozen=True)
class QueuePaths:
    root: Path
    pending: Path
    running: Path
    receipts: Path

    @classmethod
    def under(cls, root: Path) -> "QueuePaths":
        base = Path(root)
        pending = base / "pending"
        running = base / "running"
        receipts = base / "receipts"
        pending.mkdir(parents=True, exist_ok=True)
        running.mkdir(parents=True, exist_ok=True)
        receipts.mkdir(parents=True, exist_ok=True)
        return cls(base, pending, running, receipts)


@dataclass(frozen=True)
class JobEnvelope:
    task_id: str
    repository: str
    worktree_path: str
    branch: str
    expected_head: str
    base_sha: str
    lease_session_id: str
    provider: str
    resume_packet_sha256: str
    resume_packet_path: str
    created_at: str
    deadline_at: str

    def to_json(self) -> str:
        return json.dumps(asdict(self), sort_keys=True, separators=(",", ":"))

@dataclass(frozen=True)
class WorkerReceipt:
    task_id: str
    lease_session_id: str
    provider: str
    status: str
    resulting_head: str
    changed_paths: tuple[str, ...]
    validations: tuple[str, ...]
    diagnostics: str
    started_at: str
    ended_at: str
    model: str = ""
    input_tokens: int | None = None
    output_tokens: int | None = None
    cached_tokens: int | None = None

    def to_json(self) -> str:
        return json.dumps(asdict(self), sort_keys=True, separators=(",", ":"))


def _strict_dataclass(cls: type, payload: dict[str, Any]):
    expected = {field.name for field in fields(cls)}
    actual = set(payload)
    if actual != expected:
        unknown = sorted(actual - expected)
        missing = sorted(expected - actual)
        raise JobValidationError(f"invalid fields unknown={unknown} missing={missing}")
    try:
        return cls(**payload)
    except TypeError as exc:
        raise JobValidationError("invalid payload") from exc


def load_job(path: Path) -> JobEnvelope:
    try:
        payload = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise JobValidationError("invalid job JSON") from exc
    if not isinstance(payload, dict):
        raise JobValidationError("job JSON must be an object")
    return _strict_dataclass(JobEnvelope, payload)


def load_receipt(path: Path) -> WorkerReceipt:
    try:
        payload = json.loads(Path(path).read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise JobValidationError("invalid receipt JSON") from exc
    if not isinstance(payload, dict):
        raise JobValidationError("receipt JSON must be an object")
    payload = dict(payload)
    payload["changed_paths"] = tuple(payload.get("changed_paths") or ())
    payload["validations"] = tuple(payload.get("validations") or ())
    return _strict_dataclass(WorkerReceipt, payload)

def validate_job(job: JobEnvelope, *, root: Path, now: datetime) -> Path:
    if not _TASK_RE.fullmatch(job.task_id):
        raise JobValidationError("unsafe task id")
    if not _REPO_RE.fullmatch(job.repository):
        raise JobValidationError("unsafe repository")
    if not _ID_RE.fullmatch(job.lease_session_id):
        raise JobValidationError("unsafe lease session id")
    if job.provider.lower() not in _ALLOWED_PROVIDERS:
        raise JobValidationError("forbidden provider")
    if not _SHA40_RE.fullmatch(job.expected_head) or not _SHA40_RE.fullmatch(job.base_sha):
        raise JobValidationError("invalid git sha")
    if not _SHA256_RE.fullmatch(job.resume_packet_sha256):
        raise JobValidationError("invalid resume packet digest")
    created = _parse_time(job.created_at)
    deadline = _parse_time(job.deadline_at)
    current = now.astimezone(UTC)
    if deadline <= created or deadline <= current:
        raise JobValidationError("job expired")
    root_resolved = Path(root).resolve(strict=True)
    try:
        worktree = Path(job.worktree_path).resolve(strict=True)
        worktree.relative_to(root_resolved)
    except (OSError, ValueError) as exc:
        raise JobValidationError("worktree outside worker root") from exc
    if worktree == root_resolved:
        raise JobValidationError("worker root itself is not a task worktree")
    return worktree

def _atomic_write(directory: Path, filename: str, data: bytes) -> Path:
    directory.mkdir(parents=True, exist_ok=True)
    lock = directory / f".{filename}.lock"
    lock_fd = os.open(lock, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    os.close(lock_fd)
    temp = directory / f".{filename}.{os.getpid()}.tmp"
    try:
        fd = os.open(temp, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        try:
            with os.fdopen(fd, "wb", closefd=True) as handle:
                handle.write(data)
                handle.flush()
                os.fsync(handle.fileno())
            target = directory / filename
            os.replace(temp, target)
            dir_fd = os.open(directory, os.O_RDONLY)
            try:
                os.fsync(dir_fd)
            finally:
                os.close(dir_fd)
            return target
        except Exception:
            try:
                os.close(fd)
            except OSError:
                pass
            raise
    except Exception:
        try:
            temp.unlink()
        except FileNotFoundError:
            pass
        try:
            lock.unlink()
        except FileNotFoundError:
            pass
        raise

def atomic_write_job(paths: QueuePaths, job: JobEnvelope) -> Path:
    filename = f"{job.task_id}--{job.lease_session_id}.json"
    return _atomic_write(paths.pending, filename, job.to_json().encode("utf-8"))


def claim_pending_job(paths: QueuePaths, pending_path: Path) -> Path | None:
    source = Path(pending_path)
    if source.parent.resolve() != paths.pending.resolve():
        raise JobValidationError("pending job outside queue")
    target = paths.running / source.name
    try:
        os.link(source, target)
    except (FileExistsError, FileNotFoundError):
        return None
    try:
        source.unlink()
        return target
    except Exception:
        try:
            target.unlink()
        except FileNotFoundError:
            pass
        raise


def _validate_receipt(receipt: WorkerReceipt, max_diagnostics_bytes: int) -> None:
    if not _TASK_RE.fullmatch(receipt.task_id):
        raise JobValidationError("unsafe receipt task id")
    if not _ID_RE.fullmatch(receipt.lease_session_id):
        raise JobValidationError("unsafe receipt lease session id")
    if receipt.provider.lower() not in _ALLOWED_PROVIDERS:
        raise JobValidationError("forbidden receipt provider")
    if receipt.status not in {"completed", "failed", "timeout", "rejected"}:
        raise JobValidationError("invalid receipt status")
    if receipt.resulting_head and not _SHA40_RE.fullmatch(receipt.resulting_head):
        raise JobValidationError("invalid receipt git sha")
    if len(receipt.diagnostics.encode("utf-8")) > max_diagnostics_bytes:
        raise JobValidationError("receipt diagnostics exceed size limit")
    _parse_time(receipt.started_at)
    ended = _parse_time(receipt.ended_at)
    if ended < _parse_time(receipt.started_at):
        raise JobValidationError("receipt ended before it started")


def atomic_write_receipt(paths: QueuePaths, receipt: WorkerReceipt, *, max_diagnostics_bytes: int = 8192) -> Path:
    _validate_receipt(receipt, max_diagnostics_bytes)
    filename = f"{receipt.task_id}--{receipt.lease_session_id}.json"
    return _atomic_write(paths.receipts, filename, receipt.to_json().encode("utf-8"))
