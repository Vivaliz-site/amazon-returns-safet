from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import tempfile
import unittest

import tools.continuity.job_queue as job_queue
from tools.continuity.job_queue import (
    JobEnvelope,
    QueuePaths,
    WorkerReceipt,
    atomic_write_job,
    atomic_write_receipt,
    claim_next_pending,
    claim_pending_job,
)

UTC = timezone.utc


class StaleClaimRecoveryTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.paths = QueuePaths.under(Path(self.tmp.name) / "jobs")
        self.worktree = Path(self.tmp.name) / "worktrees" / "task"
        self.worktree.mkdir(parents=True)

    def job(self, *, task_id: str, session: str, created: datetime, deadline: datetime) -> JobEnvelope:
        return JobEnvelope(
            task_id=task_id,
            repository="Vivaliz-site/amazon-returns-safet",
            worktree_path=str(self.worktree),
            branch=f"agent/{task_id}-continuity",
            expected_head="b" * 40,
            base_sha="a" * 40,
            lease_session_id=session,
            provider="gemini",
            resume_packet_sha256="c" * 64,
            resume_packet_path=str(self.paths.root / "packets" / "packet.txt"),
            task_evidence_sha256="d" * 64,
            task_evidence_path=str(self.paths.root / "packets" / "evidence.json"),
            created_at=created.isoformat(),
            deadline_at=deadline.isoformat(),
        )

    def test_claim_next_recovers_expired_running_before_newer_pending(self):
        current = datetime.now(tz=UTC)
        first_job = self.job(
            task_id="TASK-STALE-001",
            session="continuity-TASK-STALE-001-gemini-123456789",
            created=current - timedelta(minutes=40),
            deadline=current - timedelta(seconds=1),
        )
        second_job = self.job(
            task_id="TASK-STALE-002",
            session="continuity-TASK-STALE-002-gemini-123456789",
            created=current - timedelta(minutes=1),
            deadline=current + timedelta(minutes=20),
        )
        first = atomic_write_job(self.paths, first_job)
        second = atomic_write_job(self.paths, second_job)
        os.utime(first, ns=(1, 1))
        os.utime(second, ns=(2, 2))
        running = claim_pending_job(self.paths, first)
        self.assertIsNotNone(running)
        claimed_before_deadline = (current - timedelta(seconds=2)).timestamp()
        os.utime(running, (claimed_before_deadline, claimed_before_deadline))

        claimed = claim_next_pending(self.paths)

        self.assertIsNotNone(claimed)
        self.assertEqual(first.name, claimed.name)
        self.assertTrue(second.exists())

    def test_reclaimer_is_fail_closed_for_active_receipted_or_divergent_claims(self):
        reclaim = getattr(job_queue, "reclaim_expired_running_claims", None)
        self.assertIsNotNone(reclaim, "reclaim_expired_running_claims must exist")
        current = datetime.now(tz=UTC)

        active_job = self.job(
            task_id="TASK-ACTIVE-001",
            session="continuity-TASK-ACTIVE-001-gemini-123456789",
            created=current - timedelta(minutes=1),
            deadline=current + timedelta(minutes=20),
        )
        active_pending = atomic_write_job(self.paths, active_job)
        active_running = claim_pending_job(self.paths, active_pending)

        receipted_job = self.job(
            task_id="TASK-RECEIPT-001",
            session="continuity-TASK-RECEIPT-001-gemini-123456789",
            created=current - timedelta(minutes=40),
            deadline=current - timedelta(minutes=10),
        )
        receipted_pending = atomic_write_job(self.paths, receipted_job)
        receipted_running = claim_pending_job(self.paths, receipted_pending)
        old = (current - timedelta(minutes=20)).timestamp()
        os.utime(receipted_running, (old, old))
        atomic_write_receipt(
            self.paths,
            WorkerReceipt(
                task_id=receipted_job.task_id,
                lease_session_id=receipted_job.lease_session_id,
                provider="gemini",
                status="rejected",
                resulting_head="",
                changed_paths=(),
                validations=(),
                diagnostics="expired",
                started_at=current.isoformat(),
                ended_at=current.isoformat(),
            ),
        )

        divergent_job = self.job(
            task_id="TASK-DIVERGE-001",
            session="continuity-TASK-DIVERGE-001-gemini-123456789",
            created=current - timedelta(minutes=40),
            deadline=current - timedelta(minutes=10),
        )
        divergent_pending = atomic_write_job(self.paths, divergent_job)
        divergent_running = claim_pending_job(self.paths, divergent_pending)
        os.utime(divergent_running, (old, old))
        divergent_running.write_bytes(divergent_running.read_bytes() + b"\n")

        self.assertEqual((), reclaim(self.paths, current))
        self.assertTrue(active_running.exists())
        self.assertTrue(receipted_running.exists())
        self.assertTrue(divergent_running.exists())


class UnresolvedQueueTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.paths = QueuePaths.under(Path(self.tmp.name) / "jobs")
        self.markers = self.paths.root.parent / "publisher"
        self.markers.mkdir(parents=True)
        self.worktree = Path(self.tmp.name) / "worktrees" / "task"
        self.worktree.mkdir(parents=True)
        now = datetime.now(tz=UTC)
        self.job = JobEnvelope(
            task_id="TASK-TERM-001",
            repository="Vivaliz-site/amazon-returns-safet",
            worktree_path=str(self.worktree),
            branch="agent/TASK-TERM-001-continuity",
            expected_head="b" * 40,
            base_sha="a" * 40,
            lease_session_id="continuity-TASK-TERM-001-gemini-123456789",
            provider="gemini",
            resume_packet_sha256="c" * 64,
            resume_packet_path=str(self.paths.root / "packets" / "packet.txt"),
            task_evidence_sha256="d" * 64,
            task_evidence_path=str(self.paths.root / "packets" / "evidence.json"),
            created_at=(now - timedelta(minutes=5)).isoformat(),
            deadline_at=(now + timedelta(minutes=20)).isoformat(),
        )
        self.now = now

    def _receipt(self) -> Path:
        return atomic_write_receipt(
            self.paths,
            WorkerReceipt(
                task_id=self.job.task_id,
                lease_session_id=self.job.lease_session_id,
                provider="gemini",
                status="rejected",
                resulting_head="",
                changed_paths=(),
                validations=(),
                diagnostics="job expired",
                started_at=self.now.isoformat(),
                ended_at=self.now.isoformat(),
            ),
        )

    def test_terminal_receipt_and_marker_do_not_count_as_unresolved_work(self):
        unresolved = getattr(job_queue, "unresolved_queue_jobs", None)
        self.assertIsNotNone(unresolved, "unresolved_queue_jobs must exist")
        pending = atomic_write_job(self.paths, self.job)
        running = claim_pending_job(self.paths, pending)
        self._receipt()
        marker = self.markers / f"{pending.name}.done.json"
        marker.write_text(json.dumps({"classification": "skipped", "status": "rejected"}) + "\n", encoding="utf-8")

        self.assertEqual((), unresolved(self.paths, self.markers))
        self.assertTrue(pending.exists())
        self.assertTrue(running.exists())

    def test_incomplete_published_marker_remains_unresolved(self):
        unresolved = getattr(job_queue, "unresolved_queue_jobs", None)
        self.assertIsNotNone(unresolved, "unresolved_queue_jobs must exist")
        pending = atomic_write_job(self.paths, self.job)
        running = claim_pending_job(self.paths, pending)
        atomic_write_receipt(
            self.paths,
            WorkerReceipt(
                task_id=self.job.task_id,
                lease_session_id=self.job.lease_session_id,
                provider="gemini",
                status="completed",
                resulting_head="b" * 40,
                changed_paths=(),
                validations=(),
                diagnostics="ok",
                started_at=self.now.isoformat(),
                ended_at=self.now.isoformat(),
            ),
        )
        marker_path = self.markers / f"{pending.name}.done.json"
        marker_path.write_text(json.dumps({"classification": "published"}) + "\n", encoding="utf-8")

        self.assertEqual((pending.name,), unresolved(self.paths, self.markers))
        self.assertTrue(running.exists())

    def test_missing_or_invalid_terminal_marker_remains_unresolved(self):
        unresolved = getattr(job_queue, "unresolved_queue_jobs", None)
        self.assertIsNotNone(unresolved, "unresolved_queue_jobs must exist")
        pending = atomic_write_job(self.paths, self.job)
        claim_pending_job(self.paths, pending)
        self._receipt()

        self.assertEqual((pending.name,), unresolved(self.paths, self.markers))
        marker = self.markers / f"{pending.name}.done.json"
        marker.write_text(json.dumps({"classification": "published"}) + "\n", encoding="utf-8")
        self.assertEqual((pending.name,), unresolved(self.paths, self.markers))


class PilotQueueContractTest(unittest.TestCase):
    def test_pilot_preflight_uses_terminal_aware_queue_gate(self):
        text = Path("scripts/run-continuity-pilot.py").read_text(encoding="utf-8")
        self.assertIn("unresolved_queue_jobs", text)
        self.assertIn("unresolved_queue_jobs(queue, marker_dir)", text)
        self.assertNotIn("pending/running continuity jobs exist before pilot", text)


if __name__ == "__main__":
    unittest.main()
