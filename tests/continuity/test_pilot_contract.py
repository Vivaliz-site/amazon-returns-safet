from pathlib import Path
import unittest


class PilotContractTest(unittest.TestCase):
    def test_wrapper_is_fail_closed_and_never_enables_automation(self):
        text = Path("scripts/run-continuity-pilot.sh").read_text(encoding="utf-8")
        self.assertIn("set -euo pipefail", text)
        self.assertIn("run-continuity-pilot.py", text)
        self.assertNotIn("enable-continuity-auto-dispatch.py", text)

    def test_pilot_requires_full_interruption_recovery_and_publication_evidence(self):
        text = Path("scripts/run-continuity-pilot.py").read_text(encoding="utf-8")
        for marker in (
            "AGENT_LOST", "NEEDS_RESUME", "GEMINI", "PR", "CI", "MERGED",
            "secret_boundary", "auto_dispatch", "systemctl", "agent-continuity-worker.service",
            "agent-continuity-publisher.service", "pilot-evidence.json",
        ):
            self.assertIn(marker, text)
        self.assertIn("https://github.com/Vivaliz-site/amazon-returns-safet.git", text)
        self.assertIn("/home/ubuntu/amazon-returns-deploy-source", text)

    def test_pilot_job_envelope_uses_claimed_task_as_source_of_truth(self):
        text = Path("scripts/run-continuity-pilot.py").read_text(encoding="utf-8")
        start = text.index("job = JobEnvelope(")
        end = text.index("\n    )", start)
        envelope = text[start:end]
        for marker in (
            "task_id=claimed.task_id",
            "repository=claimed.repository",
            "worktree_path=claimed.worktree_path",
            "branch=claimed.branch",
        ):
            self.assertIn(marker, envelope)
        self.assertIn('job_id = f"{claimed.task_id}--{claimed.agent_session_id}"', text)

    def test_worker_credential_preflight_matches_systemd_supplementary_group(self):
        text = Path("scripts/run-continuity-pilot.py").read_text(encoding="utf-8")
        self.assertIn('CONTROLLER_GROUP = "agent-continuity"', text)
        self.assertIn('primary_group=WORKER_USER, supplementary_group=CONTROLLER_GROUP', text)
        self.assertIn('"-g", primary_group', text)
        self.assertIn('"-G", supplementary_group', text)

    def test_worker_gemini_smoke_uses_worker_accessible_cwd(self):
        text = Path("scripts/run-continuity-pilot.py").read_text(encoding="utf-8")
        self.assertIn('cwd=Path(f"/var/lib/{WORKER_USER}")', text)

    def test_pilot_has_bounded_remote_polling_and_exact_sha_checks(self):
        text = Path("scripts/run-continuity-pilot.py").read_text(encoding="utf-8")
        self.assertIn("POLL_LIMIT", text)
        self.assertIn("POLL_SECONDS", text)
        self.assertIn("source_sha", text)
        self.assertIn("runtime_sha", text)
        self.assertIn("merged_sha", text)
        self.assertIn("origin/main", text)
