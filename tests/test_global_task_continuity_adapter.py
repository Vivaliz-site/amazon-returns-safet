from __future__ import annotations

import importlib.util
import os
import sys
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
ADAPTER = ROOT / "scripts" / "agent_task_state.py"


def load_adapter():
    spec = importlib.util.spec_from_file_location("global_task_state_adapter_test", ADAPTER)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load continuity adapter")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class GlobalTaskContinuityAdapterTests(unittest.TestCase):
    def setUp(self) -> None:
        self.mod = load_adapter()

    def test_repository_identity_is_pinned(self) -> None:
        self.assertEqual(self.mod.REPOSITORY, "Vivaliz-site/amazon-returns-safet")

    def test_adapter_targets_canonical_a1_runtime(self) -> None:
        self.assertEqual(
            str(self.mod.DEFAULT_CONTROLLER),
            "/home/ubuntu/shopvivaliz-deploy/current/scripts/agent_task_state.py",
        )
        self.assertEqual(
            str(self.mod.DEFAULT_RUNTIME_DIR),
            "/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state",
        )

    def test_environment_stamps_repository_and_runtime(self) -> None:
        env = self.mod.build_controller_env({"PATH": "/usr/bin"})
        self.assertEqual(env["SHOPVIVALIZ_TASK_REPOSITORY"], "Vivaliz-site/amazon-returns-safet")
        self.assertEqual(
            env["SHOPVIVALIZ_AGENT_TASK_STATE_DIR"],
            "/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state",
        )

    def test_missing_controller_fails_closed_without_local_fallback(self) -> None:
        with mock.patch.dict(os.environ, {"SHOPVIVALIZ_CONTINUITY_STATE_CLI": "/definitely/missing/controller.py"}, clear=False):
            with mock.patch.object(sys, "argv", [str(ADAPTER), "show", "--task", "fixture"]):
                self.assertEqual(self.mod.main(), 69)

    def test_self_test_needs_no_controller(self) -> None:
        with mock.patch.object(sys, "argv", [str(ADAPTER), "--adapter-self-test"]):
            self.assertEqual(self.mod.main(), 0)


if __name__ == "__main__":
    unittest.main()
