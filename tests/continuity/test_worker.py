from __future__ import annotations

from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import tomllib
import unittest
from unittest.mock import patch

from tests.continuity.git_fixture import GitFixture
from tools.continuity.job_queue import JobEnvelope, QueuePaths, WorkerReceipt, atomic_write_job
from tools.continuity.ledger import Ledger
from tools.continuity.model import Classification, TaskRecord, TaskStatus
from tools.continuity.worker import (
    ProcessResult,
    WorkerConfig,
    WorkerRunResult,
    build_worker_env,
    run_one_job,
    run_process,
)
from tools.continuity.worktrees import create_worker_task_worktree, prepare_worker_source

UTC = timezone.utc
NOW = datetime(2026, 9, 16, 12, 0, tzinfo=UTC)

class FakeRunner:
    def __init__(self, result: ProcessResult | None = None):
        self.calls: list[dict[str, object]] = []
        self.result = result or ProcessResult(0, '{"stats":{"input_tokens":11,"output_tokens":7,"cached_tokens":3}}', "", False)

    def __call__(self, argv, *, cwd, input_text, env, timeout_seconds):
        self.calls.append({
            "argv": tuple(argv), "cwd": Path(cwd), "input": input_text,
            "env": dict(env), "timeout": timeout_seconds,
        })
        return self.result


class WorkerTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.source = prepare_worker_source(
            str(self.fx.remote), self.root / "sources", "Vivaliz-site/amazon-returns-safet"
        )
        self.worker_base = self.root / "worker"
        self.task_id = "TASK-WORKER-001"
        self.worktree = create_worker_task_worktree(
            self.source, self.worker_base, self.task_id, "pilot", "origin/main", os.getuid()
        )
        self.ledger_path = self.root / "ledger.sqlite3"
        self.ledger = Ledger(self.ledger_path)
        self.session = "continuity-TASK-WORKER-001-gemini-12345678"
        self.ledger.create_task(TaskRecord(
            task_id=self.task_id, repository="Vivaliz-site/amazon-returns-safet", host="test-host",
            worktree_path=str(self.worktree.path), branch=self.worktree.branch,
            base_sha=self.worktree.head, current_head=self.worktree.head,
            objective="worker pilot", status=TaskStatus.NEEDS_RESUME,
            classification=Classification.NEEDS_RESUME, priority=10,
            created_at=NOW, updated_at=NOW,
        ))
        self.ledger.claim(self.task_id, "gemini", self.session, NOW, 1800)
        self.paths = QueuePaths.under(self.root / "jobs")
        self.packet_dir = self.paths.root / "packets"
        self.packet_dir.mkdir(parents=True, exist_ok=True)
        self.policy = self.root / "gemini-admin-policy.toml"
        self.policy.write_text("# test policy\n", encoding="utf-8")

    def config(self, *, timeout_seconds: int = 5) -> WorkerConfig:
        return WorkerConfig(
            ledger_path=self.ledger_path,
            queue_paths=self.paths,
            worker_root=self.worker_base / "worktrees",
            worker_uid=os.getuid(),
            gemini_bin="/opt/node-v24.20.0-linux-arm64/bin/gemini",
            model="gemini-test-model",
            policy_path=self.policy,
            timeout_seconds=timeout_seconds,
            base_env={"GEMINI_API_KEY": "fake-gemini-key", "PATH": os.environ.get("PATH", "")},
        )

    def job(self, **overrides) -> JobEnvelope:
        packet = "resume safely\n"
        packet_path = self.packet_dir / f"{self.task_id}--{self.session}.txt"
        packet_path.write_text(packet, encoding="utf-8")
        data = dict(
            task_id=self.task_id, repository="Vivaliz-site/amazon-returns-safet",
            worktree_path=str(self.worktree.path), branch=self.worktree.branch,
            expected_head=self.worktree.head, base_sha=self.worktree.head,
            lease_session_id=self.session, provider="gemini",
            resume_packet_sha256=hashlib.sha256(packet.encode()).hexdigest(),
            resume_packet_path=str(packet_path), created_at=NOW.isoformat(),
            deadline_at=(NOW + timedelta(minutes=20)).isoformat(),
        )
        data.update(overrides)
        return JobEnvelope(**data)

    def enqueue(self, job: JobEnvelope) -> Path:
        return atomic_write_job(self.paths, job)

    def test_worker_rejects_head_mismatch_without_running_gemini(self):
        self.enqueue(self.job(expected_head="0" * 40))
        runner = FakeRunner()
        result = run_one_job(self.config(), now=NOW, runner=runner)
        self.assertEqual("rejected_head_mismatch", result.classification)
        self.assertEqual([], runner.calls)
        self.assertTrue(result.receipt_path.exists())

    def test_worker_rejects_expired_or_wrong_lease(self):
        self.enqueue(self.job(lease_session_id="wrong-session-1234"))
        runner = FakeRunner()
        result = run_one_job(self.config(), now=NOW, runner=runner)
        self.assertEqual("rejected_lease", result.classification)
        self.assertEqual([], runner.calls)

    def test_worker_rejects_dirty_state_not_in_ledger_evidence(self):
        (self.worktree.path / "unexpected.txt").write_text("dirty\n", encoding="utf-8")
        self.enqueue(self.job())
        runner = FakeRunner()
        result = run_one_job(self.config(), now=NOW, runner=runner)
        self.assertEqual("rejected_unexpected_worktree_state", result.classification)
        self.assertEqual([], runner.calls)

    def test_worker_environment_strips_unrelated_credentials(self):
        env = build_worker_env({
            "GEMINI_API_KEY": "g", "PATH": "/usr/bin", "GH_TOKEN": "gh",
            "GITHUB_TOKEN": "github", "OPENAI_API_KEY": "openai",
            "ANTHROPIC_API_KEY": "anthropic", "CLAUDE_API_KEY": "claude",
            "SSH_AUTH_SOCK": "/tmp/agent.sock", "AWS_SECRET_ACCESS_KEY": "aws",
        })
        self.assertEqual("g", env["GEMINI_API_KEY"])
        self.assertEqual("/usr/bin", env["PATH"])
        for key in (
            "GH_TOKEN", "GITHUB_TOKEN", "OPENAI_API_KEY", "ANTHROPIC_API_KEY",
            "CLAUDE_API_KEY", "SSH_AUTH_SOCK", "AWS_SECRET_ACCESS_KEY",
        ):
            self.assertNotIn(key, env)

    def test_success_records_bounded_token_telemetry(self):
        self.enqueue(self.job())
        runner = FakeRunner(ProcessResult(
            0, '{"stats":{"input_tokens":11,"output_tokens":7,"cached_tokens":3}}', "", False
        ))
        result = run_one_job(self.config(), now=NOW, runner=runner)
        self.assertEqual("completed", result.classification)
        payload = json.loads(result.receipt_path.read_text(encoding="utf-8"))
        self.assertEqual(11, payload["input_tokens"])
        self.assertEqual(7, payload["output_tokens"])
        self.assertEqual(3, payload["cached_tokens"])
        self.assertEqual("gemini-test-model", payload["model"])

    def test_worker_invokes_only_gemini_with_policy_and_packet_on_stdin(self):
        self.enqueue(self.job())
        runner = FakeRunner()
        result = run_one_job(self.config(), now=NOW, runner=runner)
        self.assertEqual("completed", result.classification)
        self.assertEqual(1, len(runner.calls))
        call = runner.calls[0]
        argv = call["argv"]
        self.assertIn("--admin-policy", argv)
        self.assertIn(str(self.policy), argv)
        self.assertIn("--model", argv)
        self.assertIn("gemini-test-model", argv)
        self.assertIn("--skip-trust", argv)
        self.assertEqual("resume safely\n", call["input"])
        self.assertNotIn("GH_TOKEN", call["env"])
        self.assertEqual(str(Path(self.config().gemini_bin).parent), call["env"]["PATH"].split(os.pathsep)[0])

    def test_worker_rejects_rooter_until_separately_certified(self):
        self.enqueue(self.job(provider="rooter"))
        runner = FakeRunner()
        result = run_one_job(self.config(), now=NOW, runner=runner)
        self.assertEqual("rejected_provider", result.classification)
        self.assertEqual([], runner.calls)

    def test_worker_rejects_resume_packet_digest_mismatch(self):
        self.enqueue(self.job(resume_packet_sha256="f" * 64))
        runner = FakeRunner()
        result = run_one_job(self.config(), now=NOW, runner=runner)
        self.assertEqual("rejected_packet_digest", result.classification)
        self.assertEqual([], runner.calls)

    def test_timeout_kills_process_group(self):
        class SleepingProc:
            pid = 43210
            returncode = None
            def __init__(self):
                self.calls = 0
            def communicate(self, input=None, timeout=None):
                self.calls += 1
                if self.calls == 1:
                    raise subprocess.TimeoutExpired(cmd="gemini", timeout=timeout)
                self.returncode = -15
                return ("", "timed out")

        proc = SleepingProc()
        with patch("tools.continuity.worker.subprocess.Popen", return_value=proc), \
             patch("tools.continuity.worker.os.killpg") as killpg:
            result = run_process(
                ["gemini", "--prompt", ""], cwd=self.worktree.path,
                input_text="packet", env={"PATH": "/usr/bin"}, timeout_seconds=1,
            )
        self.assertTrue(result.timed_out)
        self.assertTrue(killpg.called)
        self.assertEqual(43210, killpg.call_args.args[0])

    def test_drain_mode_processes_until_idle(self):
        completed = WorkerRunResult("completed", Path("/tmp/receipt-1"), "TASK-1", "session-1")
        idle = WorkerRunResult("idle", Path(), "", "")
        with patch("tools.continuity.worker._config_from_json", return_value=self.config()), \
             patch("tools.continuity.worker.run_one_job", side_effect=[completed, completed, idle]) as run:
            from tools.continuity.worker import main
            rc = main(["--config", "/tmp/config.json", "--drain"])
        self.assertEqual(0, rc)
        self.assertEqual(3, run.call_count)

    def test_drain_mode_finishes_queue_but_returns_failure_if_any_job_failed(self):
        failed = WorkerRunResult("provider_failed", Path("/tmp/receipt-f"), "TASK-F", "session-f")
        idle = WorkerRunResult("idle", Path(), "", "")
        with patch("tools.continuity.worker._config_from_json", return_value=self.config()), \
             patch("tools.continuity.worker.run_one_job", side_effect=[failed, idle]) as run:
            from tools.continuity.worker import main
            rc = main(["--config", "/tmp/config.json", "--drain"])
        self.assertEqual(1, rc)
        self.assertEqual(2, run.call_count)

    def test_admin_policy_is_valid_deny_by_default_toml(self):
        policy = Path("deploy/continuity/gemini-admin-policy.toml")
        data = tomllib.loads(policy.read_text(encoding="utf-8"))
        rules = data["rule"]
        self.assertTrue(any(r.get("toolName") == "*" and r.get("decision") == "deny" for r in rules))
        shell_denies = [r for r in rules if r.get("toolName") == "run_shell_command" and r.get("decision") == "deny"]
        self.assertTrue(any("git push" in str(r.get("commandRegex", "")) for r in shell_denies))
        allowed = set()
        for rule in rules:
            if rule.get("decision") != "allow":
                continue
            names = rule.get("toolName")
            allowed.update(names if isinstance(names, list) else [names])
        for name in ("read_file", "write_file", "replace", "glob", "grep_search", "list_directory"):
            self.assertIn(name, allowed)
        policy_text = policy.read_text(encoding="utf-8")
        self.assertIn(".github/workflows", policy_text)


if __name__ == "__main__":
    unittest.main()
