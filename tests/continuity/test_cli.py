from contextlib import redirect_stdout
from datetime import datetime, timezone
from io import StringIO
import json
from pathlib import Path
import tempfile
import unittest
import sys

from tests.continuity.git_fixture import GitFixture
from tools.continuity.cli import main
from tools.continuity.ledger import Ledger
from tools.continuity.model import Classification, TaskRecord, TaskStatus

UTC = timezone.utc


class CliTest(unittest.TestCase):
    def setUp(self):
        self.fx = GitFixture()
        self.addCleanup(self.fx.close)
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = Path(self.tmp.name)
        self.ledger_path = root / "ledger.sqlite3"
        self.config_path = root / "config.json"
        self.config_path.write_text(json.dumps({
            "ledger_path": str(self.ledger_path),
            "worktree_root": str(root / "controller"),
            "auto_dispatch": False,
            "repositories": [{
                "repository": "Vivaliz-site/amazon-returns-safet",
                "host": "test-host",
                "path": str(self.fx.repo),
                "base_ref": "origin/main",
                "base_branch": "main",
                "github_enabled": False,
            }],
        }), encoding="utf-8")

    def invoke(self, *args):
        out = StringIO()
        with redirect_stdout(out):
            rc = main(["--config", str(self.config_path), *args])
        return rc, out.getvalue()

    def test_scan_json_is_parseable(self):
        self.fx.write("dirty.txt", "pending\n")
        rc, output = self.invoke("scan", "--json")
        self.assertEqual(0, rc)
        payload = json.loads(output)
        self.assertEqual(1, len(payload))
        self.assertIn("untracked", payload[0])

    def test_dispatch_preview_uses_configured_agent_without_claiming(self):
        config = json.loads(self.config_path.read_text(encoding="utf-8"))
        config["agents"] = [{"name": "codex", "command": [sys.executable, "-c", "pass"], "enabled": True}]
        config["auto_dispatch"] = False
        self.config_path.write_text(json.dumps(config), encoding="utf-8")
        ledger = Ledger(self.ledger_path)
        now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)
        ledger.create_task(TaskRecord(
            task_id="TASK-DISPATCH", repository="Vivaliz-site/amazon-returns-safet", host="test-host",
            worktree_path=str(self.fx.repo), branch="main",
            base_sha=self.fx.git("rev-parse", "origin/main").stdout.strip(),
            current_head=self.fx.git("rev-parse", "HEAD").stdout.strip(), objective="preview",
            status=TaskStatus.NEEDS_RESUME, classification=Classification.NEEDS_RESUME,
            priority=10, created_at=now, updated_at=now,
        ))
        rc, output = self.invoke("dispatch", "--json")
        self.assertEqual(0, rc)
        decision = json.loads(output)
        self.assertEqual("codex", decision["agent"])
        self.assertNotIn("command", decision)
        self.assertFalse(decision["claimed"])
        self.assertFalse(decision["launched"])
        self.assertIsNone(Ledger(self.ledger_path).get_task("TASK-DISPATCH").lease_expires_at)

    def test_associate_converts_orphan_without_moving_worktree(self):
        self.fx.write("orphan.txt", "pending\n")
        rc, output = self.invoke("reconcile", "--audit-only", "--json")
        self.assertEqual(0, rc)
        orphan_id = json.loads(output)[0]["task_id"]
        rc, output = self.invoke(
            "associate", orphan_id, "TASK-20260913-CLI",
            "--objective", "finish recovered work", "--json"
        )
        self.assertEqual(0, rc)
        task = json.loads(output)
        self.assertEqual("TASK-20260913-CLI", task["task_id"])
        self.assertEqual(str(self.fx.repo.resolve()), str(Path(task["worktree_path"]).resolve()))
        self.assertEqual("NEEDS_RESUME", task["classification"])

    def test_queue_json_is_deterministic(self):
        ledger = Ledger(self.ledger_path)
        now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)
        for task_id, priority in (("TASK-B", 20), ("TASK-A", 10)):
            ledger.create_task(TaskRecord(
                task_id=task_id, repository="org/repo", host="host", worktree_path="/tmp/" + task_id,
                branch="agent/" + task_id, base_sha="a" * 40, current_head="b" * 40,
                objective="test", status=TaskStatus.NEEDS_RESUME,
                classification=Classification.NEEDS_RESUME, priority=priority,
                created_at=now, updated_at=now,
            ))
        rc1, output1 = self.invoke("queue", "--json")
        rc2, output2 = self.invoke("queue", "--json")
        self.assertEqual((0, 0), (rc1, rc2))
        self.assertEqual(output1, output2)
        self.assertEqual(["TASK-A", "TASK-B"], [item["task_id"] for item in json.loads(output1)])


if __name__ == "__main__":
    unittest.main()
