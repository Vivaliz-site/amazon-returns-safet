from pathlib import Path
import json
import unittest


class SystemdContractTest(unittest.TestCase):
    def test_service_is_hardened_and_avoids_private_home_workdir(self):
        service = Path("deploy/systemd/agent-continuity-controller.service").read_text(encoding="utf-8")
        self.assertIn("python3 -m tools.continuity.cli reconcile", service)
        self.assertIn("--audit-only", service)
        self.assertIn("python3 -m tools.continuity.cli dispatch", service)
        self.assertIn("User=agent-continuity", service)
        self.assertIn("NoNewPrivileges=true", service)
        self.assertIn("PrivateTmp=true", service)
        self.assertIn("ProtectSystem=strict", service)
        self.assertIn("ProtectHome=read-only", service)
        self.assertIn("ReadWritePaths=/var/lib/agent-continuity /srv/continuity", service)
        self.assertIn("WorkingDirectory=/opt/agent-continuity/current", service)
        self.assertIn(
            "BindReadOnlyPaths=/home/ubuntu/amazon-returns-deploy-source:"
            "/srv/continuity/repositories/amazon-returns-safet",
            service,
        )
        self.assertNotIn("WorkingDirectory=/home/ubuntu", service)

    def test_timer_is_persistent_and_thirty_minutes(self):
        timer = Path("deploy/systemd/agent-continuity-controller.timer").read_text(encoding="utf-8")
        self.assertIn("OnUnitActiveSec=1800", timer)
        self.assertNotIn("OnUnitActiveSec=300", timer)
        self.assertIn("Persistent=true", timer)

    def test_installer_preserves_config_and_installs_owned_runtime(self):
        installer = Path("scripts/install-continuity-controller.sh").read_text(encoding="utf-8")
        self.assertIn("CONFIG_PATH=", installer)
        self.assertIn("config file is required", installer)
        self.assertNotIn("cat > \"$CONFIG_PATH\"", installer)
        self.assertIn('RUNTIME_ROOT="/opt/agent-continuity"', installer)
        self.assertIn("REPOSITORY_MOUNT_ROOT=", installer)
        self.assertIn("tools/continuity", installer)
        self.assertIn(".source-sha", installer)
        self.assertIn('chown root:"$SERVICE_USER" "$CONFIG_PATH"', installer)
        self.assertIn('install -d -m 0750 -o root -g "$SERVICE_USER" "$CONFIG_DIR"', installer)
        self.assertIn('git -c "safe.directory=$REPO_ROOT" -C "$REPO_ROOT" rev-parse HEAD', installer)

    def test_production_config_is_audit_only_and_uses_accessible_mount(self):
        config = json.loads(Path("deploy/continuity/config.production.json").read_text(encoding="utf-8"))
        self.assertFalse(config["auto_dispatch"])
        repo = config["repositories"][0]
        self.assertEqual("/srv/continuity/repositories/amazon-returns-safet", repo["path"])
        self.assertFalse(repo["github_enabled"])
        self.assertTrue(all(not agent["enabled"] for agent in config["agents"]))

    def test_auto_deploy_migrates_legacy_config_and_tracks_runtime_sha(self):
        script = Path("scripts/auto-deploy.sh").read_text(encoding="utf-8")
        self.assertIn("deploy/continuity/config.production.json", script)
        self.assertIn("scripts/install-continuity-controller.sh", script)
        self.assertIn("scripts/migrate-continuity-config.py", script)
        self.assertIn("/opt/agent-continuity/.source-sha", script)
        self.assertIn("systemctl start agent-continuity-controller.service", script)
        self.assertLess(script.index("scripts/install-continuity-controller.sh"), script.index("auto_deploy_skipped=already_current"))

    def test_ci_runs_continuity_tests_and_syntax(self):
        ci = Path(".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("Continuity controller tests", ci)
        self.assertIn("python3 -m unittest discover -s tests/continuity", ci)
        self.assertIn("python3 -m py_compile tools/continuity/*.py", ci)
        self.assertIn("python3 -m py_compile scripts/migrate-continuity-config.py", ci)
        self.assertIn("bash -n scripts/install-continuity-controller.sh", ci)
        self.assertIn("bash -n scripts/auto-deploy.sh", ci)


if __name__ == "__main__":
    unittest.main()
