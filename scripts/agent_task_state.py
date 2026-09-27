#!/usr/bin/env python3
"""Fail-closed adapter to the canonical ShopVivaliz task-continuity controller."""
from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path
from typing import Mapping

REPOSITORY = "Vivaliz-site/amazon-returns-safet"
DEFAULT_CONTROLLER = Path("/home/ubuntu/shopvivaliz-deploy/current/scripts/agent_task_state.py")
DEFAULT_RUNTIME_DIR = Path("/home/ubuntu/shopvivaliz-deploy/shared/agent-task-state")
CONTROLLER_ENV = "SHOPVIVALIZ_CONTINUITY_STATE_CLI"
RUNTIME_ENV = "SHOPVIVALIZ_AGENT_TASK_STATE_DIR"


def controller_path() -> Path:
    configured = os.getenv(CONTROLLER_ENV, "").strip()
    return Path(configured).expanduser() if configured else DEFAULT_CONTROLLER


def build_controller_env(base: Mapping[str, str] | None = None) -> dict[str, str]:
    env = dict(base or os.environ)
    env["SHOPVIVALIZ_TASK_REPOSITORY"] = REPOSITORY
    env[RUNTIME_ENV] = str(
        Path(env.get(RUNTIME_ENV, "")).expanduser()
        if env.get(RUNTIME_ENV, "").strip()
        else DEFAULT_RUNTIME_DIR
    )
    return env


def main() -> int:
    if sys.argv[1:] == ["--adapter-self-test"]:
        print(json.dumps({"ok": True, "repository": REPOSITORY, "mode": "canonical-controller-adapter"}))
        return 0

    controller = controller_path()
    try:
        same_file = controller.resolve() == Path(__file__).resolve()
    except OSError:
        same_file = False
    if same_file or not controller.is_file():
        print(
            json.dumps(
                {
                    "ok": False,
                    "error": "global_continuity_controller_unavailable",
                    "repository": REPOSITORY,
                },
                sort_keys=True,
            ),
            file=sys.stderr,
        )
        return 69

    completed = subprocess.run(
        [sys.executable, str(controller), *sys.argv[1:]],
        env=build_controller_env(),
        check=False,
    )
    return int(completed.returncode)


if __name__ == "__main__":
    raise SystemExit(main())
