from __future__ import annotations

from contextlib import contextmanager
from dataclasses import replace
from datetime import datetime, timedelta, timezone
import json
from pathlib import Path
import sqlite3
from typing import Iterator

from .model import Classification, TaskRecord, TaskStatus, ensure_utc

UTC = timezone.utc
_JSON_FIELDS = {"dirty_files", "staged_files", "untracked_files", "local_test_results", "known_failures"}
_DATETIME_FIELDS = {"last_heartbeat_at", "lease_expires_at", "created_at", "updated_at"}


class LeaseConflict(RuntimeError):
    pass


class TaskNotFound(KeyError):
    pass


class Ledger:
    def __init__(self, path: Path):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._init_schema()

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.path, timeout=30, isolation_level=None)
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys=ON")
        conn.execute("PRAGMA journal_mode=WAL")
        return conn

    @contextmanager
    def _connection(self) -> Iterator[sqlite3.Connection]:
        conn = self._connect()
        try:
            yield conn
        finally:
            conn.close()

    def _init_schema(self) -> None:
        with self._connection() as conn:
            conn.executescript(
                """
                CREATE TABLE IF NOT EXISTS tasks (
                    task_id TEXT PRIMARY KEY,
                    repository TEXT NOT NULL,
                    host TEXT NOT NULL,
                    worktree_path TEXT NOT NULL,
                    branch TEXT NOT NULL,
                    base_sha TEXT NOT NULL,
                    current_head TEXT NOT NULL,
                    agent_type TEXT,
                    agent_session_id TEXT,
                    objective TEXT NOT NULL,
                    status TEXT NOT NULL,
                    classification TEXT NOT NULL,
                    last_heartbeat_at TEXT,
                    lease_expires_at TEXT,
                    last_checkpoint_sha TEXT,
                    dirty_files TEXT NOT NULL DEFAULT '[]',
                    staged_files TEXT NOT NULL DEFAULT '[]',
                    untracked_files TEXT NOT NULL DEFAULT '[]',
                    ahead_count INTEGER NOT NULL DEFAULT 0,
                    behind_count INTEGER NOT NULL DEFAULT 0,
                    pull_request TEXT,
                    ci_state TEXT,
                    verification_state TEXT,
                    blocker TEXT,
                    next_action TEXT,
                    priority INTEGER NOT NULL DEFAULT 1000,
                    local_test_results TEXT NOT NULL DEFAULT '[]',
                    known_failures TEXT NOT NULL DEFAULT '[]',
                    patch_summary TEXT,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    revision INTEGER NOT NULL DEFAULT 0
                );
                CREATE INDEX IF NOT EXISTS idx_tasks_resume_queue
                ON tasks(classification, priority, updated_at, task_id);
                CREATE TABLE IF NOT EXISTS dispatch_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id TEXT NOT NULL REFERENCES tasks(task_id),
                    agent_type TEXT NOT NULL,
                    attempted_at TEXT NOT NULL,
                    outcome TEXT NOT NULL,
                    detail TEXT
                );
                """
            )

    @contextmanager
    def _immediate(self) -> Iterator[sqlite3.Connection]:
        conn = self._connect()
        try:
            conn.execute("BEGIN IMMEDIATE")
            yield conn
            conn.execute("COMMIT")
        except Exception:
            conn.execute("ROLLBACK")
            raise
        finally:
            conn.close()

    @staticmethod
    def _iso(value: datetime | None) -> str | None:
        value = ensure_utc(value)
        return None if value is None else value.isoformat()

    @staticmethod
    def _parse_dt(value: str | None) -> datetime | None:
        if not value:
            return None
        return datetime.fromisoformat(value).astimezone(UTC)

    def _task_values(self, task: TaskRecord) -> dict[str, object]:
        values = task.as_public_dict()
        for name in _JSON_FIELDS:
            values[name] = json.dumps(values[name], separators=(",", ":"), sort_keys=True)
        return values

    def create_task(self, task: TaskRecord) -> None:
        values = self._task_values(task)
        cols = list(values)
        placeholders = ",".join("?" for _ in cols)
        with self._connection() as conn:
            conn.execute(
                f"INSERT INTO tasks ({','.join(cols)}) VALUES ({placeholders})",
                [values[c] for c in cols],
            )

    def get_task(self, task_id: str) -> TaskRecord | None:
        with self._connection() as conn:
            row = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
        return self._row_to_task(row) if row else None

    def list_tasks(self) -> list[TaskRecord]:
        with self._connection() as conn:
            rows = conn.execute("SELECT * FROM tasks ORDER BY task_id").fetchall()
        return [self._row_to_task(row) for row in rows]

    def _row_to_task(self, row: sqlite3.Row) -> TaskRecord:
        data = dict(row)
        data["status"] = TaskStatus(data["status"])
        data["classification"] = Classification(data["classification"])
        for name in _JSON_FIELDS:
            data[name] = json.loads(data.get(name) or "[]")
        for name in _DATETIME_FIELDS:
            data[name] = self._parse_dt(data.get(name))
        return TaskRecord(**data)

    def claim(self, task_id: str, agent_type: str, session_id: str, now: datetime, lease_seconds: int) -> TaskRecord:
        now = ensure_utc(now)
        assert now is not None
        expires = now + timedelta(seconds=lease_seconds)
        with self._immediate() as conn:
            row = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
            if not row:
                raise TaskNotFound(task_id)
            current = self._row_to_task(row)
            if current.lease_expires_at and current.lease_expires_at > now and current.agent_session_id != session_id:
                raise LeaseConflict(f"active lease held by {current.agent_session_id}")
            conn.execute(
                """UPDATE tasks SET agent_type=?, agent_session_id=?, status=?, classification=?,
                   last_heartbeat_at=?, lease_expires_at=?, updated_at=?, revision=revision+1
                   WHERE task_id=?""",
                (
                    agent_type,
                    session_id,
                    TaskStatus.CLAIMED.value,
                    Classification.ACTIVE.value,
                    self._iso(now),
                    self._iso(expires),
                    self._iso(now),
                    task_id,
                ),
            )
            row = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
        return self._row_to_task(row)

    def heartbeat(self, task_id: str, session_id: str, now: datetime, lease_seconds: int) -> TaskRecord:
        now = ensure_utc(now)
        assert now is not None
        expires = now + timedelta(seconds=lease_seconds)
        with self._immediate() as conn:
            row = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
            if not row:
                raise TaskNotFound(task_id)
            current = self._row_to_task(row)
            if current.agent_session_id != session_id:
                raise LeaseConflict("session does not own lease")
            if current.lease_expires_at and current.lease_expires_at < now:
                raise LeaseConflict("lease already expired")
            conn.execute(
                """UPDATE tasks SET last_heartbeat_at=?, lease_expires_at=?, updated_at=?,
                   classification=?, revision=revision+1 WHERE task_id=?""",
                (self._iso(now), self._iso(expires), self._iso(now), Classification.ACTIVE.value, task_id),
            )
            row = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
        return self._row_to_task(row)

    def expire_leases(self, now: datetime) -> list[TaskRecord]:
        now = ensure_utc(now)
        assert now is not None
        expired_ids: list[str] = []
        with self._immediate() as conn:
            rows = conn.execute(
                "SELECT task_id FROM tasks WHERE lease_expires_at IS NOT NULL AND lease_expires_at < ? AND classification=?",
                (self._iso(now), Classification.ACTIVE.value),
            ).fetchall()
            expired_ids = [row["task_id"] for row in rows]
            for task_id in expired_ids:
                conn.execute(
                    """UPDATE tasks SET status=?, classification=?, agent_session_id=NULL,
                       lease_expires_at=NULL, next_action=?, updated_at=?, revision=revision+1 WHERE task_id=?""",
                    (
                        TaskStatus.NEEDS_RESUME.value,
                        Classification.NEEDS_RESUME.value,
                        "resume from existing worktree",
                        self._iso(now),
                        task_id,
                    ),
                )
            rows = [conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone() for task_id in expired_ids]
        return [self._row_to_task(row) for row in rows]

    def list_resume_queue(self) -> list[TaskRecord]:
        with self._connection() as conn:
            rows = conn.execute(
                """SELECT * FROM tasks
                   WHERE classification IN (?, ?, ?, ?, ?, ?)
                   ORDER BY priority ASC, updated_at ASC, task_id ASC""",
                (
                    Classification.NEEDS_RESUME.value,
                    Classification.ORPHAN_UNKNOWN.value,
                    Classification.READY_FOR_PR.value,
                    Classification.PR_BLOCKED.value,
                    Classification.MERGED_UNVERIFIED.value,
                    Classification.BLOCKED_EXTERNAL.value,
                ),
            ).fetchall()
        return [self._row_to_task(row) for row in rows]

    def update_fields(self, task_id: str, **fields: object) -> TaskRecord:
        if not fields:
            task = self.get_task(task_id)
            if task is None:
                raise TaskNotFound(task_id)
            return task
        allowed = set(TaskRecord.__dataclass_fields__) - {"task_id", "revision"}
        unknown = set(fields) - allowed
        if unknown:
            raise ValueError(f"unknown task fields: {sorted(unknown)}")
        normalized: dict[str, object] = {}
        for key, value in fields.items():
            if key in _JSON_FIELDS:
                normalized[key] = json.dumps(value or [], separators=(",", ":"), sort_keys=True)
            elif key in _DATETIME_FIELDS:
                normalized[key] = self._iso(value)  # type: ignore[arg-type]
            elif isinstance(value, (TaskStatus, Classification)):
                normalized[key] = value.value
            else:
                normalized[key] = value
        with self._immediate() as conn:
            existing = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
            if not existing:
                raise TaskNotFound(task_id)
            clauses = [f"{key}=?" for key in normalized]
            clauses.append("revision=revision+1")
            conn.execute(
                f"UPDATE tasks SET {', '.join(clauses)} WHERE task_id=?",
                [*normalized.values(), task_id],
            )
            row = conn.execute("SELECT * FROM tasks WHERE task_id=?", (task_id,)).fetchone()
        return self._row_to_task(row)

    def record_dispatch_attempt(self, task_id: str, agent_type: str, attempted_at: datetime, outcome: str, detail: str | None = None) -> None:
        with self._connection() as conn:
            conn.execute(
                "INSERT INTO dispatch_attempts(task_id,agent_type,attempted_at,outcome,detail) VALUES(?,?,?,?,?)",
                (task_id, agent_type, self._iso(attempted_at), outcome, detail),
            )

    def list_dispatch_attempts(self, task_id: str) -> list[dict[str, object]]:
        with self._connection() as conn:
            rows = conn.execute(
                "SELECT id,task_id,agent_type,attempted_at,outcome,detail FROM dispatch_attempts WHERE task_id=? ORDER BY id",
                (task_id,),
            ).fetchall()
        return [dict(row) for row in rows]

    def associate_orphan(self, orphan_id: str, new_task_id: str, objective: str, now: datetime) -> TaskRecord:
        now = ensure_utc(now)
        assert now is not None
        with self._immediate() as conn:
            row = conn.execute("SELECT * FROM tasks WHERE task_id=?", (orphan_id,)).fetchone()
            if not row:
                raise TaskNotFound(orphan_id)
            source = self._row_to_task(row)
            if source.classification is not Classification.ORPHAN_UNKNOWN:
                raise ValueError("only ORPHAN_UNKNOWN findings can be associated")
            if conn.execute("SELECT 1 FROM tasks WHERE task_id=?", (new_task_id,)).fetchone():
                raise ValueError(f"task already exists: {new_task_id}")
            associated = replace(
                source,
                task_id=new_task_id,
                objective=objective,
                status=TaskStatus.NEEDS_RESUME,
                classification=Classification.NEEDS_RESUME,
                agent_session_id=None,
                lease_expires_at=None,
                next_action="resume the existing associated worktree",
                created_at=now,
                updated_at=now,
                revision=0,
            )
            values = self._task_values(associated)
            cols = list(values)
            conn.execute(
                f"INSERT INTO tasks ({','.join(cols)}) VALUES ({','.join('?' for _ in cols)})",
                [values[col] for col in cols],
            )
            conn.execute(
                """UPDATE tasks SET status=?, classification=?, next_action=?, updated_at=?,
                   revision=revision+1 WHERE task_id=?""",
                (
                    TaskStatus.SUPERSEDED.value,
                    Classification.SUPERSEDED.value,
                    f"associated to {new_task_id}",
                    self._iso(now),
                    orphan_id,
                ),
            )
        return associated
