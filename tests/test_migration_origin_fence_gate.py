#!/usr/bin/env python3
"""Regression guard: the origin must never revive Amazon writers after the Marina handoff."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
FENCE = "/etc/shopvivaliz/migration/origin-workers-fenced"


class OriginFenceTests(unittest.TestCase):
    def test_auto_deploy_fails_closed_before_mutating_work(self):
        source = (ROOT / "scripts/auto-deploy.sh").read_text()
        marker = "auto_deploy_skipped=origin_workers_fenced"
        self.assertIn(FENCE, source)
        self.assertIn(marker, source)
        self.assertLess(source.index(FENCE), source.index("[[ -d \"$repo/.git\" ]]"))
        self.assertLess(source.index(marker), source.index("bootstrap_continuity()"))
        self.assertIn("exit 0", source[source.index(FENCE):source.index("bootstrap_continuity()")])

    def test_direct_provision_fails_closed_before_lock_and_backup(self):
        source = (ROOT / "scripts/provision-production.sh").read_text()
        marker = "provision_blocked=origin_workers_fenced"
        self.assertIn(FENCE, source)
        self.assertIn(marker, source)
        self.assertLess(source.index(FENCE), source.index("exec 9>/run/lock/amazon-returns-provision.lock"))
        self.assertIn("exit 75", source[source.index(FENCE):source.index("exec 9>/run/lock/amazon-returns-provision.lock")])

    def test_all_three_writer_units_enforce_origin_fence(self):
        for name in (
            "amazon-returns-safet.service",
            "amazon-returns-seller-central-browser.service",
            "amazon-returns-olist-erp-browser.service",
        ):
            with self.subTest(unit=name):
                content = (ROOT / "deploy/systemd" / name).read_text()
                self.assertIn(
                    "ConditionPathExists=!" + FENCE,
                    content.split("[Service]", 1)[0],
                )


if __name__ == "__main__":
    unittest.main()
