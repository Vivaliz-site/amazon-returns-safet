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
        self.assertIn("ReadWritePaths=/var/lib/agent-continuity/controller /var/lib/agent-continuity/jobs/pending /var/lib/agent-continuity/jobs/packets", service)
        self.assertNotIn("ReadWritePaths=/var/lib/agent-continuity /srv/continuity", service)
        self.assertIn("UMask=0027", service)
        self.assertNotIn("UMask=0077", service)
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
        self.assertIn("chmod 0755 \"$STAGE_DIR\"", installer)
        self.assertIn('chown root:"$SERVICE_USER" "$CONFIG_PATH"', installer)
        self.assertIn('install -d -m 0750 -o root -g "$SERVICE_USER" "$CONFIG_DIR"', installer)
        self.assertIn('git -c "safe.directory=$REPO_ROOT" -C "$REPO_ROOT" rev-parse HEAD', installer)

    def test_production_config_is_audit_only_and_uses_accessible_mount(self):
        config = json.loads(Path("deploy/continuity/config.production.json").read_text(encoding="utf-8"))
        self.assertFalse(config["auto_dispatch"])
        repo = config["repositories"][0]
        self.assertEqual("/srv/continuity/repositories/amazon-returns-safet", repo["path"])
        self.assertEqual("/var/lib/agent-continuity/controller/ledger.sqlite3", config["ledger_path"])
        self.assertFalse(repo["github_enabled"])
        self.assertTrue(all(not agent["enabled"] for agent in config["agents"]))
        self.assertEqual({"gemini", "rooter"}, {agent["name"] for agent in config["agents"]})

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
        self.assertIn("python3 -m py_compile scripts/migrate-continuity-state-layout.py", ci)
        self.assertIn("python3 -m py_compile scripts/enable-continuity-auto-dispatch.py", ci)
        self.assertIn("bash -n scripts/install-continuity-controller.sh", ci)
        self.assertIn("bash -n scripts/provision-continuity-gemini-env.sh", ci)
        self.assertIn("bash -n scripts/provision-continuity-publisher-key.sh", ci)
        self.assertIn("bash -n scripts/auto-deploy.sh", ci)

    def test_worker_and_publisher_are_separate_hardened_users(self):
        worker = Path("deploy/systemd/agent-continuity-worker.service").read_text(encoding="utf-8")
        publisher = Path("deploy/systemd/agent-continuity-publisher.service").read_text(encoding="utf-8")
        for directive in ("NoNewPrivileges=true", "PrivateTmp=true", "PrivateDevices=true",
                          "ProtectSystem=strict", "ProtectHome=true", "RestrictSUIDSGID=true",
                          "LockPersonality=true", "TasksMax=", "MemoryMax=", "CPUQuota=", "TimeoutStartSec="):
            self.assertIn(directive, worker)
            self.assertIn(directive, publisher)
        self.assertIn("User=agent-continuity-worker", worker)
        self.assertIn("Environment=HOME=/var/lib/agent-continuity-worker", worker)
        self.assertIn("/var/lib/agent-continuity-worker", worker)
        self.assertIn("EnvironmentFile=-/etc/agent-continuity/gemini.env", worker)
        self.assertIn("python3 -m tools.continuity.worker", worker)
        self.assertIn("--once", worker)
        self.assertNotIn("--drain", worker)
        self.assertIn("Restart=on-failure", worker)
        self.assertIn("UMask=0027", worker)
        self.assertNotIn("agent-continuity-jobs", worker)
        self.assertIn("ReadWritePaths=/srv/continuity/sources /srv/continuity/worktrees /var/lib/agent-continuity/jobs/running /var/lib/agent-continuity/jobs/receipts /var/lib/agent-continuity-worker", worker)
        self.assertIn("InaccessiblePaths=/var/lib/agent-continuity/controller", worker)
        self.assertNotIn("ReadOnlyPaths=/var/lib/agent-continuity/controller", worker)
        self.assertNotIn("publisher_ssh_key", worker)
        self.assertIn("User=agent-continuity-publisher", publisher)
        self.assertIn("Environment=HOME=/var/lib/agent-continuity-publisher", publisher)
        self.assertIn("/var/lib/agent-continuity-publisher", publisher)
        self.assertIn("LoadCredential=publisher_ssh_key:/etc/agent-continuity/publisher/id_ed25519", publisher)
        self.assertIn("python3 -m tools.continuity.publisher", publisher)
        self.assertNotIn("agent-continuity-jobs", publisher)
        self.assertNotIn("ReadWritePaths=/srv/continuity/sources", publisher)
        self.assertIn("ReadOnlyPaths=/srv/continuity/sources /srv/continuity/worktrees /var/lib/agent-continuity/jobs/running /var/lib/agent-continuity/jobs/receipts", publisher)
        self.assertIn("InaccessiblePaths=/var/lib/agent-continuity/controller", publisher)
        self.assertNotIn("GEMINI_API_KEY", publisher)
        self.assertNotIn("GH_TOKEN", publisher)

    def test_worker_code_has_no_controller_ledger_dependency(self):
        worker = Path("tools/continuity/worker.py").read_text(encoding="utf-8").lower()
        for forbidden in ("sqlite3", "ledger_path", "controller/ledger", "ledger"):
            self.assertNotIn(forbidden, worker)

    def test_worker_and_publisher_path_units_watch_only_owned_queues(self):
        worker = Path("deploy/systemd/agent-continuity-worker.path").read_text(encoding="utf-8")
        publisher = Path("deploy/systemd/agent-continuity-publisher.path").read_text(encoding="utf-8")
        self.assertIn("/var/lib/agent-continuity/jobs/pending", worker)
        self.assertIn("/var/lib/agent-continuity/jobs/running", worker)
        self.assertIn("agent-continuity-worker.service", worker)
        self.assertIn("/var/lib/agent-continuity/jobs/receipts", publisher)
        self.assertIn("agent-continuity-publisher.service", publisher)

    def test_installer_creates_trust_domains_and_installs_policy_without_secrets(self):
        installer = Path("scripts/install-continuity-controller.sh").read_text(encoding="utf-8")
        for user in ("agent-continuity-worker", "agent-continuity-publisher"):
            self.assertIn(user, installer)
        self.assertIn("/var/lib/$WORKER_USER", installer)
        self.assertIn("/var/lib/$PUBLISHER_USER", installer)
        self.assertIn("gemini-admin-policy.toml", installer)
        self.assertNotIn('\nJOB_GROUP="agent-continuity-jobs"', installer)
        self.assertNotIn('usermod -a -G "$JOB_GROUP"', installer)
        self.assertIn('gpasswd -d "$user" "$LEGACY_JOB_GROUP"', installer)
        self.assertIn('2750 -o "$SERVICE_USER" -g "$WORKER_USER"', installer)
        self.assertIn('2750 -o "$WORKER_USER" -g "$PUBLISHER_USER"', installer)
        self.assertIn('2750 -o "$WORKER_USER" -g "$SERVICE_USER"', installer)
        self.assertIn("migrate-continuity-state-layout.py", installer)
        self.assertIn("normalize_queue_files", installer)
        self.assertIn('chown -R --no-dereference "$WORKER_USER:$SERVICE_USER"', installer)
        self.assertIn('find "$tree" -type d -exec chmod', installer)
        self.assertIn("ledger.sqlite3", installer)
        self.assertIn("chmod 0640", installer)
        self.assertIn("agent-continuity-worker.path", installer)
        self.assertIn("agent-continuity-publisher.path", installer)
        self.assertNotIn("GEMINI_API_KEY=", installer)
        self.assertNotIn("github_pat_", installer)

    def test_installer_prepares_only_audit_only_runtime_config(self):
        installer = Path("scripts/install-continuity-controller.sh").read_text(encoding="utf-8")
        self.assertIn("prepare-continuity-runtime-config.py", installer)
        self.assertIn('id -u "$WORKER_USER"', installer)
        self.assertIn("CONTINUITY_GEMINI_BIN", installer)
        self.assertIn("/opt/node-v", installer)
        ci = Path(".github/workflows/ci.yml").read_text(encoding="utf-8")
        self.assertIn("python3 -m py_compile scripts/prepare-continuity-runtime-config.py", ci)

    def test_installer_does_not_run_audit_only_config_prep_after_enablement(self):
        installer = Path("scripts/install-continuity-controller.sh").read_text(encoding="utf-8")
        self.assertIn("CONTINUITY_AUTO_DISPATCH", installer)
        self.assertIn("runtime_config_prep_skipped=auto_dispatch_enabled", installer)
        self.assertIn('if [[ "$CONTINUITY_AUTO_DISPATCH" == "false" ]]', installer)
        self.assertIn("systemctl stop agent-continuity-controller.timer", installer)
        self.assertLess(installer.index("systemctl stop agent-continuity-controller.timer"), installer.index("migrate-continuity-state-layout.py"))

    def test_provisioners_are_explicit_and_secret_minimal(self):
        gemini = Path("scripts/provision-continuity-gemini-env.sh").read_text(encoding="utf-8")
        publisher = Path("scripts/provision-continuity-publisher-key.sh").read_text(encoding="utf-8")
        self.assertIn("GEMINI_ENV_PROVISIONED=true", gemini)
        self.assertIn("0640", gemini)
        self.assertIn("agent-continuity-worker", gemini)
        self.assertNotIn("cat $", gemini)
        self.assertIn("ssh-keygen", publisher)
        self.assertIn("read_only=false", publisher)
        self.assertIn("0600", publisher)
        self.assertIn("api.github.com/meta", publisher)
        self.assertIn("deploy-key.json", publisher)
        self.assertIn("repository", publisher)



if __name__ == "__main__":
    unittest.main()
