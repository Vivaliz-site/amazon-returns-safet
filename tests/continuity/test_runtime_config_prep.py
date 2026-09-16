from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

SCRIPT = Path("scripts/prepare-continuity-runtime-config.py")
spec = importlib.util.spec_from_file_location("prepare_continuity_runtime_config", SCRIPT)
if spec is None or spec.loader is None:
    raise ImportError(str(SCRIPT))
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
prepare = module.prepare


class RuntimeConfigPrepTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path = Path(self.tmp.name) / "config.json"

    def write_config(self, *, auto_dispatch=False):
        payload = {
            "ledger_path": "/var/lib/agent-continuity/ledger.sqlite3",
            "auto_dispatch": auto_dispatch,
            "custom_operator_field": "preserve-me",
            "repositories": [{
                "repository": "Vivaliz-site/amazon-returns-safet",
                "host": "shopvivaliz-free-a1",
                "path": "/srv/continuity/repositories/amazon-returns-safet",
                "github_enabled": False,
            }],
            "agents": [
                {"name": "gemini", "command": ["gemini"], "enabled": True},
                {"name": "rooter", "command": ["rooter"], "enabled": False},
            ],
        }
        self.path.write_text(json.dumps(payload), encoding="utf-8")
        os.chmod(self.path, 0o640)
        return payload

    def test_adds_safe_runtime_fields_without_enabling_automation(self):
        before = self.write_config()
        changed = prepare(self.path, worker_uid=1234, gemini_bin="/opt/node/bin/gemini")
        self.assertTrue(changed)
        after = json.loads(self.path.read_text())
        self.assertFalse(after["auto_dispatch"])
        self.assertEqual(before["custom_operator_field"], after["custom_operator_field"])
        self.assertEqual(1234, after["worker_uid"])
        self.assertEqual("/opt/node/bin/gemini", after["gemini_bin"])
        self.assertEqual("/var/lib/agent-continuity/jobs", after["job_queue_root"])
        self.assertEqual("/srv/continuity/worktrees", after["worker_root"])
        self.assertEqual("/etc/agent-continuity/gemini-admin-policy.toml", after["gemini_admin_policy"])
        self.assertEqual("/etc/agent-continuity/publisher-known-hosts", after["publisher_known_hosts"])
        self.assertEqual(["Vivaliz-site/amazon-returns-safet"], after["publisher_allowed_repositories"])
        self.assertEqual(3, after["max_auto_attempts"])
        self.assertEqual(900, after["worker_timeout_seconds"])
        self.assertGreaterEqual(after["retry_cooldown_seconds"], 1800)
        self.assertEqual(0o640, self.path.stat().st_mode & 0o777)

    def test_refuses_to_mutate_enabled_automation(self):
        original = self.write_config(auto_dispatch=True)
        with self.assertRaises(ValueError):
            prepare(self.path, worker_uid=1234, gemini_bin="/opt/node/bin/gemini")
        self.assertEqual(original, json.loads(self.path.read_text()))

    def test_second_run_is_idempotent(self):
        self.write_config()
        self.assertTrue(prepare(self.path, worker_uid=1234, gemini_bin="/opt/node/bin/gemini"))
        first = self.path.read_bytes()
        self.assertFalse(prepare(self.path, worker_uid=1234, gemini_bin="/opt/node/bin/gemini"))
        self.assertEqual(first, self.path.read_bytes())


if __name__ == "__main__":
    unittest.main()
