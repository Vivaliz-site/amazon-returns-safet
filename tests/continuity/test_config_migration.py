from importlib.util import module_from_spec, spec_from_file_location
import json
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path("scripts/migrate-continuity-config.py")
spec = spec_from_file_location("continuity_config_migration", SCRIPT)
module = module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)


class ConfigMigrationTest(unittest.TestCase):
    def _write(self, root: Path, *, auto_dispatch=False, github_enabled=False, path=None) -> Path:
        target = root / "config.json"
        target.write_text(json.dumps({
            "ledger_path": "/var/lib/agent-continuity/ledger.sqlite3",
            "auto_dispatch": auto_dispatch,
            "repositories": [{
                "repository": "Vivaliz-site/amazon-returns-safet",
                "host": "shopvivaliz-free-a1",
                "path": path or module.LEGACY_PATH,
                "github_enabled": github_enabled,
            }],
        }), encoding="utf-8")
        target.chmod(0o640)
        return target

    def test_migrates_only_known_audit_only_legacy_path(self):
        with tempfile.TemporaryDirectory() as td:
            config = self._write(Path(td))
            before_mode = config.stat().st_mode & 0o777
            self.assertTrue(module.migrate(config))
            data = json.loads(config.read_text(encoding="utf-8"))
            self.assertEqual(module.MOUNT_PATH, data["repositories"][0]["path"])
            self.assertEqual(before_mode, config.stat().st_mode & 0o777)

    def test_does_not_touch_auto_dispatch_or_remote_enabled_configs(self):
        with tempfile.TemporaryDirectory() as td:
            root = Path(td)
            auto = self._write(root, auto_dispatch=True)
            original = auto.read_text(encoding="utf-8")
            self.assertFalse(module.migrate(auto))
            self.assertEqual(original, auto.read_text(encoding="utf-8"))
        with tempfile.TemporaryDirectory() as td:
            remote = self._write(Path(td), github_enabled=True)
            original = remote.read_text(encoding="utf-8")
            self.assertFalse(module.migrate(remote))
            self.assertEqual(original, remote.read_text(encoding="utf-8"))


class ConfigMigrationCliTest(unittest.TestCase):
    def test_cli_returns_ten_when_migration_changes_config(self):
        with tempfile.TemporaryDirectory() as td:
            config = ConfigMigrationTest()._write(Path(td))
            cp = subprocess.run(
                ["python3", str(SCRIPT), str(config)],
                text=True,
                capture_output=True,
            )
            self.assertEqual(10, cp.returncode, cp.stderr)


if __name__ == "__main__":
    unittest.main()
