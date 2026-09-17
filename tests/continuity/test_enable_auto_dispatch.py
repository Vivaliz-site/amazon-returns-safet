from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest
import sys

MODULE_PATH = Path("scripts/enable-continuity-auto-dispatch.py")
spec = importlib.util.spec_from_file_location("enable_continuity_auto_dispatch", MODULE_PATH)
if spec is None or spec.loader is None:
    raise ImportError(str(MODULE_PATH))
module = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = module
spec.loader.exec_module(module)
GatePaths = module.GatePaths
enable = module.enable

SHA = "a" * 40
REPOSITORY = "Vivaliz-site/amazon-returns-safet"


class EnableAutoDispatchTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.cfg = self.root / "config.json"
        self.write_config()
        self.runtime_sha = self.root / "runtime.sha"
        self.runtime_sha.write_text(SHA + "\n", encoding="utf-8")
        self.gemini_env = self.root / "gemini.env"
        self.gemini_env.write_text(
            "GEMINI_API_KEY=fake-test-key\nGEMINI_MODEL=gemini-3.1-flash-lite\n",
            encoding="utf-8",
        )
        os.chmod(self.gemini_env, 0o640)
        self.publisher_dir = self.root / "publisher"
        self.publisher_dir.mkdir()
        os.chmod(self.publisher_dir, 0o700)
        self.publisher_key = self.publisher_dir / "id_ed25519"
        self.publisher_key.write_text("fake-private-key\n", encoding="utf-8")
        os.chmod(self.publisher_key, 0o600)
        self.known_hosts = self.root / "known_hosts"
        self.known_hosts.write_text("github.com ssh-ed25519 fake\n", encoding="utf-8")
        self.gemini_bin = self.root / "gemini"
        self.gemini_bin.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
        os.chmod(self.gemini_bin, 0o755)
        self.policy = self.root / "policy.toml"
        self.policy.write_text('[[rule]]\ntoolName="*"\ndecision="deny"\npriority=1\n', encoding="utf-8")
        self.units = []
        for name in (
            "agent-continuity-worker.service", "agent-continuity-worker.path",
            "agent-continuity-publisher.service", "agent-continuity-publisher.path",
        ):
            path = self.root / name
            path.write_text("[Unit]\n", encoding="utf-8")
            self.units.append(path)

    def write_config(self, *, agents=None):
        payload = {
            "ledger_path": str(self.root / "ledger.sqlite3"),
            "auto_dispatch": False,
            "repositories": [{"repository": REPOSITORY, "host": "host", "path": "/srv/repo"}],
            "agents": agents if agents is not None else [
                {"name": "gemini", "command": ["gemini"], "enabled": False},
                {"name": "rooter", "command": ["rooter"], "enabled": False},
            ],
        }
        self.cfg.write_text(json.dumps(payload), encoding="utf-8")
        os.chmod(self.cfg, 0o640)

    def pilot(self):
        return {
            "status": "green", "source_sha": SHA, "runtime_sha": SHA, "repository": REPOSITORY,
            "ci_green": True, "merged_sha": "b" * 40, "secret_boundary": True,
        }

    def paths(self):
        return GatePaths(
            runtime_sha=self.runtime_sha,
            worker_unit=self.units[0], worker_path_unit=self.units[1],
            publisher_unit=self.units[2], publisher_path_unit=self.units[3],
            gemini_env=self.gemini_env, publisher_dir=self.publisher_dir,
            publisher_key=self.publisher_key, known_hosts=self.known_hosts,
            gemini_bin=self.gemini_bin, policy=self.policy,
            worker_uid=os.getuid(), worker_gid=os.getgid(),
            job_queue_root=self.root / "jobs", worker_root=self.root / "worktrees",
        )

    def test_refuses_without_green_pilot_evidence(self):
        rc = enable(self.cfg, pilot=None, source_sha=SHA, paths=self.paths(), unit_enabled=lambda _: True, unit_start=lambda _: True)
        self.assertEqual(2, rc)
        self.assertFalse(json.loads(self.cfg.read_text())["auto_dispatch"])

    def test_refuses_forbidden_enabled_agents(self):
        self.write_config(agents=[{"name": "codex", "command": ["codex"], "enabled": True}])
        rc = enable(self.cfg, pilot=self.pilot(), source_sha=SHA, paths=self.paths(), unit_enabled=lambda _: True, unit_start=lambda _: True)
        self.assertEqual(2, rc)
        self.assertFalse(json.loads(self.cfg.read_text())["auto_dispatch"])

    def test_refuses_pilot_runtime_sha_mismatch(self):
        pilot = self.pilot()
        pilot["runtime_sha"] = "c" * 40
        rc = enable(self.cfg, pilot=pilot, source_sha=SHA, paths=self.paths(), unit_enabled=lambda _: True, unit_start=lambda _: True)
        self.assertEqual(2, rc)
        self.assertFalse(json.loads(self.cfg.read_text())["auto_dispatch"])

    def test_refuses_runtime_sha_mismatch_and_unsafe_env_mode(self):
        self.runtime_sha.write_text("c" * 40 + "\n", encoding="utf-8")
        self.assertEqual(2, enable(self.cfg, pilot=self.pilot(), source_sha=SHA, paths=self.paths(), unit_enabled=lambda _: True, unit_start=lambda _: True))
        self.runtime_sha.write_text(SHA + "\n", encoding="utf-8")
        os.chmod(self.gemini_env, 0o666)
        self.assertEqual(2, enable(self.cfg, pilot=self.pilot(), source_sha=SHA, paths=self.paths(), unit_enabled=lambda _: True, unit_start=lambda _: True))

    def test_refuses_when_required_path_cannot_start_before_enabling(self):
        started = []

        def start(unit):
            started.append(unit)
            return unit == "agent-continuity-worker.path"

        rc = enable(
            self.cfg, pilot=self.pilot(), source_sha=SHA, paths=self.paths(),
            unit_enabled=lambda _: True, unit_start=start,
        )
        self.assertEqual(2, rc)
        self.assertFalse(json.loads(self.cfg.read_text())["auto_dispatch"])
        self.assertEqual(
            ["agent-continuity-worker.path", "agent-continuity-publisher.path"], started
        )

    def test_success_enables_only_gemini_and_preserves_operator_fields(self):
        before = json.loads(self.cfg.read_text())
        rc = enable(self.cfg, pilot=self.pilot(), source_sha=SHA, paths=self.paths(), unit_enabled=lambda _: True, unit_start=lambda _: True)
        self.assertEqual(0, rc)
        after = json.loads(self.cfg.read_text())
        self.assertTrue(after["auto_dispatch"])
        self.assertEqual(before["repositories"], after["repositories"])
        agents = {item["name"]: item for item in after["agents"]}
        self.assertTrue(agents["gemini"]["enabled"])
        self.assertEqual([str(self.gemini_bin)], agents["gemini"]["command"])
        self.assertFalse(agents["rooter"]["enabled"])
        self.assertEqual(str(self.root / "jobs"), after["job_queue_root"])
        self.assertEqual(str(self.root / "worktrees"), after["worker_root"])
        self.assertEqual(os.getuid(), after["worker_uid"])
        self.assertEqual("gemini-3.1-flash-lite", after["gemini_model"])
        self.assertEqual(str(self.policy), after["gemini_admin_policy"])

    def test_repository_templates_remain_fail_closed_with_worker_roots(self):
        for path in (Path("deploy/continuity/config.production.json"), Path("tools/continuity/config.example.json")):
            payload = json.loads(path.read_text(encoding="utf-8"))
            self.assertFalse(payload["auto_dispatch"])
            self.assertEqual({"gemini", "rooter"}, {item["name"] for item in payload["agents"]})
            self.assertTrue(all(not item["enabled"] for item in payload["agents"]))
            self.assertEqual("/var/lib/agent-continuity/jobs", payload["job_queue_root"])
            self.assertEqual("/srv/continuity/worktrees", payload["worker_root"])
            self.assertEqual("/etc/agent-continuity/gemini-admin-policy.toml", payload["gemini_admin_policy"])



if __name__ == "__main__":
    unittest.main()
