from __future__ import annotations

from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

from tests.continuity.git_fixture import GitFixture
from tools.continuity.job_queue import (
    JobEnvelope, QueuePaths, WorkerReceipt, atomic_write_job, claim_pending_job,
)
from tools.continuity.publisher import (
    PublisherConfig, PublishSafetyError, build_publisher_env, publish_receipt,
)
from tools.continuity.worktrees import create_worker_task_worktree, prepare_worker_source

UTC = timezone.utc
NOW = datetime(2026, 9, 16, 13, 0, tzinfo=UTC)


class FakeGitRunner:
    def __init__(self, remote_sha: str | None = None):
        self.remote_sha = remote_sha
        self.calls: list[list[str]] = []

    def __call__(self, cwd, args, env):
        self.calls.append(list(args))
        if args and args[0] == "ls-remote":
            out = "" if self.remote_sha is None else f"{self.remote_sha}\trefs/heads/agent/TASK-PUBLISH-001-pilot\n"
            return subprocess.CompletedProcess(args, 0, out, "")
        if args and args[0] == "push":
            return subprocess.CompletedProcess(args, 0, "pushed\n", "")
        raise AssertionError(f"unexpected git command: {args}")


class PublisherTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        source = prepare_worker_source(
            str(self.fx.remote), self.root / "sources", "Vivaliz-site/amazon-returns-safet"
        )
        self.worker_base = self.root / "worker"
        self.task_id = "TASK-PUBLISH-001"
        self.worktree = create_worker_task_worktree(
            source, self.worker_base, self.task_id, "pilot", "origin/main", os.getuid()
        )
        subprocess.run(["git", "-C", str(self.worktree.path), "config", "user.email", "continuity@example.invalid"], check=True)
        subprocess.run(["git", "-C", str(self.worktree.path), "config", "user.name", "Continuity Test"], check=True)
        self.paths = QueuePaths.under(self.root / "jobs")
        self.session = "continuity-TASK-PUBLISH-001-gemini-12345678"
        packet_dir = self.paths.root / "packets"
        packet_dir.mkdir(parents=True, exist_ok=True)
        packet_path = packet_dir / f"{self.task_id}--{self.session}.txt"
        packet_path.write_text("publish pilot\n", encoding="utf-8")
        packet_digest = hashlib.sha256(packet_path.read_bytes()).hexdigest()
        job = JobEnvelope(
            task_id=self.task_id, repository="Vivaliz-site/amazon-returns-safet",
            worktree_path=str(self.worktree.path), branch=self.worktree.branch,
            expected_head=self.worktree.head, base_sha=self.worktree.head,
            lease_session_id=self.session, provider="gemini",
            resume_packet_sha256=packet_digest, resume_packet_path=str(packet_path),
            created_at=NOW.isoformat(), deadline_at=(NOW + timedelta(hours=1)).isoformat(),
        )
        pending = atomic_write_job(self.paths, job)
        self.assertIsNotNone(claim_pending_job(self.paths, pending))
        self.key = self.root / "publisher_key"
        self.key.write_text("fake-private-key\n", encoding="utf-8")
        self.known_hosts = self.root / "known_hosts"
        self.known_hosts.write_text("github.com fake-host-key\n", encoding="utf-8")

    def config(self) -> PublisherConfig:
        return PublisherConfig(
            queue_paths=self.paths,
            worker_root=self.worker_base / "worktrees",
            worker_uid=os.getuid(),
            allowed_repositories=frozenset({"Vivaliz-site/amazon-returns-safet"}),
            ssh_key_path=self.key,
            known_hosts_path=self.known_hosts,
            base_env={
                "PATH": os.environ.get("PATH", ""), "HOME": str(self.root),
                "GEMINI_API_KEY": "must-not-leak", "GH_TOKEN": "must-not-leak",
            },
        )

    def committed_head(self) -> str:
        target = self.worktree.path / "tests" / "continuity" / "pilot-fixtures" / "publisher.txt"
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("publisher pilot\n", encoding="utf-8")
        subprocess.run(["git", "-C", str(self.worktree.path), "add", str(target.relative_to(self.worktree.path))], check=True)
        subprocess.run(["git", "-C", str(self.worktree.path), "commit", "-m", "test: publisher pilot"], check=True, capture_output=True)
        return subprocess.run(
            ["git", "-C", str(self.worktree.path), "rev-parse", "HEAD"],
            check=True, text=True, capture_output=True,
        ).stdout.strip()

    def receipt(self, *, head: str, status: str = "completed") -> WorkerReceipt:
        return WorkerReceipt(
            task_id=self.task_id, lease_session_id=self.session, provider="gemini",
            status=status, resulting_head=head,
            changed_paths=("tests/continuity/pilot-fixtures/publisher.txt",),
            validations=("unit:pass",), diagnostics="completed",
            started_at=NOW.isoformat(), ended_at=(NOW + timedelta(seconds=5)).isoformat(),
            model="gemini-test", input_tokens=1, output_tokens=1, cached_tokens=0,
        )

    def test_publisher_environment_is_not_worker_environment(self):
        env = build_publisher_env(self.config())
        self.assertNotIn("GEMINI_API_KEY", env)
        self.assertNotIn("GH_TOKEN", env)
        self.assertNotIn("GITHUB_TOKEN", env)
        self.assertIn("GIT_SSH_COMMAND", env)
        self.assertIn(str(self.key), env["GIT_SSH_COMMAND"])

    def test_publisher_refuses_when_receipt_head_differs_from_worktree_head(self):
        head = self.committed_head()
        runner = FakeGitRunner()
        with self.assertRaises(PublishSafetyError):
            publish_receipt(self.receipt(head="0" * 40), self.config(), runner=runner)
        self.assertEqual([], runner.calls)
        self.assertNotEqual("0" * 40, head)

    def test_publisher_rejects_repo_outside_allowlist(self):
        head = self.committed_head()
        cfg = self.config()
        cfg = PublisherConfig(
            queue_paths=cfg.queue_paths, worker_root=cfg.worker_root,
            worker_uid=cfg.worker_uid, allowed_repositories=frozenset({"other/repo"}),
            ssh_key_path=cfg.ssh_key_path, known_hosts_path=cfg.known_hosts_path,
            base_env=cfg.base_env,
        )
        with self.assertRaises(PublishSafetyError):
            publish_receipt(self.receipt(head=head), cfg, runner=FakeGitRunner())

    def test_publisher_rejects_non_completed_receipt(self):
        head = self.committed_head()
        with self.assertRaises(PublishSafetyError):
            publish_receipt(self.receipt(head=head, status="failed"), self.config(), runner=FakeGitRunner())

    def test_publisher_refuses_conflicting_remote_branch(self):
        head = self.committed_head()
        runner = FakeGitRunner(remote_sha="f" * 40)
        with self.assertRaises(PublishSafetyError):
            publish_receipt(self.receipt(head=head), self.config(), runner=runner)
        self.assertEqual("ls-remote", runner.calls[0][0])
        self.assertFalse(any(call and call[0] == "push" for call in runner.calls))

    def test_publisher_pushes_exact_head_without_force(self):
        head = self.committed_head()
        runner = FakeGitRunner(remote_sha=None)
        result = publish_receipt(self.receipt(head=head), self.config(), runner=runner)
        self.assertEqual("pushed", result.status)
        self.assertEqual(head, result.head)
        push = next(call for call in runner.calls if call and call[0] == "push")
        self.assertNotIn("--force", push)
        self.assertIn(f"HEAD:refs/heads/{self.worktree.branch}", push)

    def test_publisher_accepts_existing_remote_only_at_same_sha(self):
        head = self.committed_head()
        runner = FakeGitRunner(remote_sha=head)
        result = publish_receipt(self.receipt(head=head), self.config(), runner=runner)
        self.assertEqual("already_published", result.status)
        self.assertFalse(any(call and call[0] == "push" for call in runner.calls))

    def test_publisher_rejects_dirty_worktree_after_commit(self):
        head = self.committed_head()
        (self.worktree.path / "unexpected.txt").write_text("dirty\n", encoding="utf-8")
        with self.assertRaises(PublishSafetyError):
            publish_receipt(self.receipt(head=head), self.config(), runner=FakeGitRunner())

    def test_publisher_workflow_is_agent_only_and_merges_after_checks(self):
        workflow = Path(".github/workflows/continuity-publisher.yml").read_text(encoding="utf-8")
        self.assertIn("agent/**", workflow)
        self.assertIn("contents: write", workflow)
        self.assertIn("pull-requests: write", workflow)
        self.assertIn("gh pr checks", workflow)
        self.assertIn("--watch", workflow)
        self.assertIn("gh pr merge", workflow)
        self.assertIn("--auto", workflow)
        self.assertNotIn("GH_TOKEN:", workflow)



if __name__ == "__main__":
    unittest.main()
