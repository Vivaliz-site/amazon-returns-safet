from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import tempfile
import unittest

from tools.continuity.job_queue import (
    JobEnvelope,
    JobValidationError,
    QueuePaths,
    WorkerReceipt,
    atomic_write_job,
    atomic_write_receipt,
    claim_pending_job,
    load_job,
    validate_job,
)

UTC = timezone.utc
NOW = datetime(2026, 9, 16, 8, 0, tzinfo=UTC)
SHA_A = "a" * 40
SHA_B = "b" * 40


class JobQueueTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "worktrees"
        self.worktree = self.root / "amazon-returns-safet" / "TASK-20260916-001"
        self.worktree.mkdir(parents=True)
    def job(self, **changes):
        values = dict(
            task_id="TASK-20260916-001",
            repository="Vivaliz-site/amazon-returns-safet",
            worktree_path=str(self.worktree),
            branch="agent/TASK-20260916-001-continuity",
            expected_head=SHA_B,
            base_sha=SHA_A,
            lease_session_id="continuity-TASK-20260916-001-gemini-123456789",
            provider="gemini",
            resume_packet_sha256="c" * 64,
            resume_packet_path=str(Path(self.tmp.name) / "packet.txt"),
            created_at=NOW.isoformat(),
            deadline_at=(NOW + timedelta(minutes=20)).isoformat(),
        )
        values.update(changes)
        return JobEnvelope(**values)

    def test_job_rejects_path_outside_worker_root(self):
        outside = Path(self.tmp.name) / "legacy-checkout"
        outside.mkdir()
        with self.assertRaises(JobValidationError):
            validate_job(self.job(worktree_path=str(outside)), root=self.root, now=NOW)

    def test_job_json_never_contains_secret_like_fields(self):
        payload = self.job().to_json().lower()
        for forbidden in ("token", "password", "secret", "cookie", "api_key", "private_key"):
            self.assertNotIn(forbidden, payload)
    def test_rejects_symlink_escape_and_expired_job(self):
        outside = Path(self.tmp.name) / "outside"
        outside.mkdir()
        link = self.root / "amazon-returns-safet" / "TASK-ESCAPE"
        link.symlink_to(outside, target_is_directory=True)
        with self.assertRaises(JobValidationError):
            validate_job(self.job(worktree_path=str(link)), root=self.root, now=NOW)
        with self.assertRaises(JobValidationError):
            validate_job(self.job(deadline_at=(NOW - timedelta(seconds=1)).isoformat()), root=self.root, now=NOW)

    def test_rejects_forbidden_provider_and_malformed_identity_fields(self):
        for provider in ("codex", "claude", "chatgpt"):
            with self.subTest(provider=provider), self.assertRaises(JobValidationError):
                validate_job(self.job(provider=provider), root=self.root, now=NOW)
        with self.assertRaises(JobValidationError):
            validate_job(self.job(expected_head="bad"), root=self.root, now=NOW)
        with self.assertRaises(JobValidationError):
            validate_job(self.job(lease_session_id="bad session"), root=self.root, now=NOW)

    def test_load_rejects_unknown_fields(self):
        path = Path(self.tmp.name) / "bad.json"
        payload = json.loads(self.job().to_json())
        payload["password"] = "synthetic"
        path.write_text(json.dumps(payload), encoding="utf-8")
        with self.assertRaises(JobValidationError):
            load_job(path)
    def test_atomic_job_write_refuses_duplicate_name(self):
        paths = QueuePaths.under(Path(self.tmp.name) / "queue")
        first = atomic_write_job(paths, self.job())
        self.assertTrue(first.is_file())
        self.assertEqual(0o600, first.stat().st_mode & 0o777)
        with self.assertRaises(FileExistsError):
            atomic_write_job(paths, self.job())

    def test_receipt_write_enforces_bounded_diagnostics(self):
        paths = QueuePaths.under(Path(self.tmp.name) / "queue")
        receipt = WorkerReceipt(
            task_id="TASK-20260916-001",
            lease_session_id="continuity-TASK-20260916-001-gemini-123456789",
            provider="gemini",
            status="completed",
            resulting_head=SHA_B,
            changed_paths=("tests/continuity/pilot-fixtures/example.txt",),
            validations=("unit:pass",),
            diagnostics="x" * 9000,
            started_at=NOW.isoformat(),
            ended_at=(NOW + timedelta(seconds=5)).isoformat(),
        )
        with self.assertRaises(JobValidationError):
            atomic_write_receipt(paths, receipt, max_diagnostics_bytes=1024)

    def test_only_one_concurrent_pending_claim_succeeds(self):
        paths = QueuePaths.under(Path(self.tmp.name) / "queue")
        pending = atomic_write_job(paths, self.job())
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(lambda _: claim_pending_job(paths, pending), range(2)))
        claimed = [path for path in results if path is not None]
        self.assertEqual(1, len(claimed))
        self.assertEqual(paths.running.resolve(), claimed[0].parent.resolve())
        self.assertFalse(pending.exists())

    def test_valid_job_round_trip(self):
        paths = QueuePaths.under(Path(self.tmp.name) / "queue")
        path = atomic_write_job(paths, self.job())
        loaded = load_job(path)
        self.assertEqual(self.job(), loaded)
        self.assertEqual(self.worktree.resolve(), validate_job(loaded, root=self.root, now=NOW))


if __name__ == "__main__":
    unittest.main()
