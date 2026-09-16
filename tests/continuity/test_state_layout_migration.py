from __future__ import annotations

import importlib.util
import json
import os
import subprocess
from pathlib import Path
import sys
import tempfile
import unittest

SCRIPT = Path("scripts/migrate-continuity-state-layout.py")
spec = importlib.util.spec_from_file_location("migrate_continuity_state_layout", SCRIPT)
if spec is None or spec.loader is None:
    raise ImportError(str(SCRIPT))
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
migrate = module.migrate


class StateLayoutMigrationTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / "state"
        self.root.mkdir()
        self.controller = self.root / "controller"
        self.controller.mkdir()
        self.config = Path(self.tmp.name) / "config.json"

    def write_config(self, *, auto_dispatch=False, ledger=None):
        ledger_path = ledger or (self.root / "ledger.sqlite3")
        payload = {
            "ledger_path": str(ledger_path),
            "auto_dispatch": auto_dispatch,
            "repositories": [],
        }
        self.config.write_text(json.dumps(payload), encoding="utf-8")
        os.chmod(self.config, 0o640)
        return payload

    def test_moves_legacy_ledger_and_sidecars_and_updates_config(self):
        self.write_config()
        for suffix, content in (("", b"db"), ("-wal", b"wal"), ("-shm", b"shm")):
            (self.root / f"ledger.sqlite3{suffix}").write_bytes(content)
        changed = migrate(self.config, state_root=self.root, controller_state=self.controller)
        self.assertTrue(changed)
        payload = json.loads(self.config.read_text())
        self.assertEqual(str(self.controller / "ledger.sqlite3"), payload["ledger_path"])
        for suffix, content in (("", b"db"), ("-wal", b"wal"), ("-shm", b"shm")):
            self.assertFalse((self.root / f"ledger.sqlite3{suffix}").exists())
            self.assertEqual(content, (self.controller / f"ledger.sqlite3{suffix}").read_bytes())
        self.assertEqual(0o640, self.config.stat().st_mode & 0o777)

    def test_fresh_install_updates_config_when_legacy_ledger_does_not_exist(self):
        self.write_config()
        changed = migrate(self.config, state_root=self.root, controller_state=self.controller)
        self.assertTrue(changed)
        payload = json.loads(self.config.read_text())
        self.assertEqual(str(self.controller / "ledger.sqlite3"), payload["ledger_path"])
        self.assertFalse((self.root / "ledger.sqlite3").exists())

    def test_config_already_new_but_legacy_db_present_moves_legacy_when_disabled(self):
        target = self.controller / "ledger.sqlite3"
        self.write_config(ledger=target)
        (self.root / "ledger.sqlite3").write_bytes(b"legacy")
        self.assertTrue(migrate(self.config, state_root=self.root, controller_state=self.controller))
        self.assertFalse((self.root / "ledger.sqlite3").exists())
        self.assertEqual(b"legacy", target.read_bytes())

    def test_config_already_new_refuses_legacy_db_when_enabled(self):
        target = self.controller / "ledger.sqlite3"
        self.write_config(auto_dispatch=True, ledger=target)
        (self.root / "ledger.sqlite3").write_bytes(b"legacy")
        with self.assertRaises(ValueError):
            migrate(self.config, state_root=self.root, controller_state=self.controller)
        self.assertTrue((self.root / "ledger.sqlite3").exists())
        self.assertFalse(target.exists())

    def test_refuses_when_legacy_and_target_ledgers_both_exist(self):
        target = self.controller / "ledger.sqlite3"
        target.write_bytes(b"target")
        self.write_config(ledger=target)
        (self.root / "ledger.sqlite3").write_bytes(b"legacy")
        with self.assertRaises(ValueError):
            migrate(self.config, state_root=self.root, controller_state=self.controller)

    def test_is_idempotent_when_config_already_uses_controller_ledger(self):
        target = self.controller / "ledger.sqlite3"
        target.write_bytes(b"db")
        self.write_config(ledger=target)
        self.assertFalse(migrate(self.config, state_root=self.root, controller_state=self.controller))
        self.assertEqual(b"db", target.read_bytes())

    def test_refuses_legacy_migration_when_automation_is_enabled(self):
        self.write_config(auto_dispatch=True)
        (self.root / "ledger.sqlite3").write_bytes(b"db")
        with self.assertRaises(ValueError):
            migrate(self.config, state_root=self.root, controller_state=self.controller)
        self.assertTrue((self.root / "ledger.sqlite3").exists())

    def test_cli_migrates_fresh_layout(self):
        self.write_config()
        cp = subprocess.run([
            "python3", str(SCRIPT), "--config", str(self.config),
            "--state-root", str(self.root), "--controller-state", str(self.controller),
        ], text=True, capture_output=True)
        self.assertEqual(0, cp.returncode, cp.stderr)
        self.assertEqual(str(self.controller / "ledger.sqlite3"), json.loads(self.config.read_text())["ledger_path"])

    def test_refuses_unknown_ledger_location(self):
        external = Path(self.tmp.name) / "unexpected.sqlite3"
        external.write_bytes(b"db")
        self.write_config(ledger=external)
        with self.assertRaises(ValueError):
            migrate(self.config, state_root=self.root, controller_state=self.controller)


if __name__ == "__main__":
    unittest.main()
