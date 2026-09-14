from datetime import datetime, timezone
import unittest

from tools.continuity.git_scan import GitFinding
from tools.continuity.github_state import RemoteState
from tools.continuity.model import Classification, TaskRecord, TaskStatus
from tools.continuity.resume_packet import redact, render_resume_packet

UTC = timezone.utc


class ResumePacketTest(unittest.TestCase):
    def setUp(self):
        now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)
        self.task = TaskRecord(
            task_id="TASK-20260913-001", repository="Vivaliz-site/amazon-returns-safet",
            host="shopvivaliz-free-a1", worktree_path="/srv/worktrees/TASK-20260913-001",
            branch="agent/TASK-20260913-001-continuity", base_sha="a" * 40,
            current_head="b" * 40, objective="finish continuity controller",
            status=TaskStatus.NEEDS_RESUME, classification=Classification.NEEDS_RESUME,
            agent_type="codex", last_heartbeat_at=now, dirty_files=["tools/a.py"],
            staged_files=["tests/a.py"], untracked_files=["notes.txt"], ahead_count=2,
            local_test_results=["unit: PASS"], known_failures=["none"],
            patch_summary="2 files changed", ci_state="pending", blocker=None,
            next_action="run tests then push", created_at=now, updated_at=now,
        )
        self.finding = GitFinding(
            repo="/srv/worktrees/TASK-20260913-001", branch=self.task.branch,
            head=self.task.current_head, upstream="origin/" + self.task.branch,
            modified=("tools/a.py",), staged=("tests/a.py",), untracked=("notes.txt",),
            stash_count=0, ahead_count=2, behind_count=0, detached=False,
            operation_in_progress=(), conflicted=(), worktrees=(self.task.worktree_path,), lock_files=(),
        )
        self.remote = RemoteState(
            repository=self.task.repository, branch=self.task.branch, base="main",
            branch_present=True, head_sha=self.task.current_head, pr_number=181,
            pr_url="https://github.example.invalid/pull/181", pr_state="OPEN",
            checks_state="pending", mergeable=True,
        )

    def test_redacts_common_secret_shapes(self):
        github = "ghp_" + "A" * 24
        openai = "sk-" + "B" * 24
        bearer = "Authorization" + ": Bearer " + "C" * 32
        otp = "otpauth" + "://totp/example?secret=" + "D" * 20
        pem = "-----BEGIN " + "OPENSSH PRIVATE KEY-----\nabc\n-----END " + "OPENSSH PRIVATE KEY-----"
        cookie = "Cookie: sessionid=" + "E" * 20
        password = "password=" + "F" * 20
        source = "\n".join([github, openai, bearer, otp, pem, cookie, password])
        redacted = redact(source)
        for secret in (github, openai, "C" * 32, "D" * 20, "abc", "E" * 20, "F" * 20):
            self.assertNotIn(secret, redacted)
        self.assertIn("[REDACTED]", redacted)

    def test_redacts_classic_openai_key_without_second_dash(self):
        # Synthetic fake key, classic "sk-<alphanumeric body>" shape with no
        # further dash-delimited segment (unlike "sk-proj-...").
        classic_key = "sk-" + "aZ3fQ7mN9pR1tV5xB2cD4eG6hJ8k"
        text = f"OPENAI_API_KEY={classic_key} in env"
        redacted = redact(text)
        self.assertNotIn(classic_key, redacted)
        self.assertIn("[REDACTED]", redacted)

    def test_render_is_deterministic_and_complete(self):
        first = render_resume_packet(self.task, self.finding, self.remote)
        second = render_resume_packet(self.task, self.finding, self.remote)
        self.assertEqual(first, second)
        for label in (
            "TASK_ID", "repository", "host", "worktree_path", "branch", "base_sha",
            "current_head", "objective", "status", "classification", "last_agent",
            "last_heartbeat", "commits_since_base", "changed_files", "untracked_files",
            "staged_files", "patch_summary", "operation_in_progress", "local_test_results",
            "CI_state", "PR_state", "known_failures", "blockers", "next_action",
            "safety_constraints", "completion_criteria",
        ):
            self.assertIn(label + ":", first)
        self.assertTrue(first.endswith("Registre checkpoint seguro antes de encerrar.\n"))

    def test_renderer_redacts_secrets_inside_task_fields(self):
        secret = "ghp_" + "Z" * 24
        self.task.objective = "continue using " + secret
        packet = render_resume_packet(self.task, self.finding, self.remote)
        self.assertNotIn(secret, packet)
        self.assertIn("[REDACTED]", packet)


if __name__ == "__main__":
    unittest.main()
