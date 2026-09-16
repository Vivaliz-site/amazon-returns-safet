#!/usr/bin/env python3
from __future__ import annotations

import argparse
from dataclasses import dataclass
import grp
import json
import os
from pathlib import Path
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
from typing import Callable

SHA40 = re.compile(r"^[0-9a-fA-F]{40}$")
ALLOWED_AGENTS = {"gemini", "rooter"}


@dataclass(frozen=True)
class GatePaths:
    runtime_sha: Path
    worker_unit: Path
    worker_path_unit: Path
    publisher_unit: Path
    publisher_path_unit: Path
    gemini_env: Path
    publisher_dir: Path
    publisher_key: Path
    known_hosts: Path
    gemini_bin: Path
    policy: Path
    worker_uid: int
    worker_gid: int
    job_queue_root: Path
    worker_root: Path


def _fail(message: str) -> int:
    print(f"continuity enablement refused: {message}", file=sys.stderr)
    return 2


def _mode(path: Path) -> int:
    return stat.S_IMODE(path.stat().st_mode)


def _read_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def _write_atomic_config(path: Path, payload: dict) -> None:
    original = path.stat()
    fd, temp_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temp = Path(temp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, indent=2, sort_keys=False)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, stat.S_IMODE(original.st_mode))
        if os.geteuid() == 0:
            os.chown(temp, original.st_uid, original.st_gid)
        os.replace(temp, path)
        dir_fd = os.open(path.parent, os.O_RDONLY)
        try:
            os.fsync(dir_fd)
        finally:
            os.close(dir_fd)
    finally:
        try:
            temp.unlink()
        except FileNotFoundError:
            pass


def _pilot_ok(pilot: dict | None, source_sha: str, repositories: set[str]) -> bool:
    if not isinstance(pilot, dict):
        return False
    return (
        pilot.get("status") == "green"
        and pilot.get("source_sha") == source_sha
        and pilot.get("runtime_sha") == source_sha
        and pilot.get("repository") in repositories
        and pilot.get("ci_green") is True
        and bool(SHA40.fullmatch(str(pilot.get("merged_sha") or "")))
        and pilot.get("secret_boundary") is True
    )

def enable(
    config_path: Path,
    *,
    pilot: dict | None,
    source_sha: str,
    paths: GatePaths,
    unit_enabled: Callable[[str], bool],
) -> int:
    config_path = Path(config_path)
    if not SHA40.fullmatch(source_sha):
        return _fail("invalid source SHA")
    try:
        config = json.loads(config_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return _fail("invalid operator config")
    repositories = {
        str(item.get("repository")) for item in config.get("repositories", []) if item.get("repository")
    }
    if not _pilot_ok(pilot, source_sha, repositories):
        return _fail("green pilot evidence matching SHA/repository is required")
    try:
        deployed_sha = paths.runtime_sha.read_text(encoding="utf-8").strip()
    except OSError:
        return _fail("runtime SHA evidence is unavailable")
    if deployed_sha != source_sha:
        return _fail("deployed runtime SHA does not match requested source SHA")
    required_units = (
        paths.worker_unit, paths.worker_path_unit,
        paths.publisher_unit, paths.publisher_path_unit,
    )
    if not all(path.is_file() for path in required_units):
        return _fail("worker/publisher systemd units are not installed")
    for unit in (paths.worker_path_unit.name, paths.publisher_path_unit.name):
        if not unit_enabled(unit):
            return _fail(f"required path unit is not enabled: {unit}")
    try:
        env_stat = paths.gemini_env.stat()
    except OSError:
        return _fail("Gemini environment file is unavailable")
    if _mode(paths.gemini_env) not in {0o600, 0o640}:
        return _fail("Gemini environment file permissions are too broad")
    if env_stat.st_gid != paths.worker_gid:
        return _fail("Gemini environment file group is not the worker group")
    env_values = _read_env_file(paths.gemini_env)
    if not env_values.get("GEMINI_API_KEY"):
        return _fail("Gemini credential is missing")
    model = env_values.get("GEMINI_MODEL") or "gemini-3.1-flash-lite"
    try:
        publisher_mode = _mode(paths.publisher_dir)
        key_mode = _mode(paths.publisher_key)
    except OSError:
        return _fail("publisher credential material is unavailable")
    if publisher_mode & 0o077 or not (publisher_mode & 0o700) == 0o700:
        return _fail("publisher credential directory permissions are too broad")
    if key_mode & 0o077 or not (key_mode & 0o600) == 0o600:
        return _fail("publisher private key permissions are too broad")
    if not paths.known_hosts.is_file() or (_mode(paths.known_hosts) & 0o022):
        return _fail("publisher known_hosts is missing or writable by non-owner")
    if not paths.gemini_bin.is_file() or not os.access(paths.gemini_bin, os.X_OK):
        return _fail("Gemini CLI is unavailable or not executable")
    if not paths.policy.is_file() or (_mode(paths.policy) & 0o022):
        return _fail("Gemini admin policy is missing or writable by non-owner")

    agents = config.get("agents")
    if not isinstance(agents, list):
        return _fail("agent configuration is missing")
    by_name = {str(item.get("name", "")).lower(): item for item in agents if isinstance(item, dict)}
    if set(by_name) != ALLOWED_AGENTS:
        return _fail("automatic config may contain only gemini and rooter")
    if bool(by_name["rooter"].get("enabled")):
        return _fail("rooter is not certified and must remain disabled")

    by_name["gemini"]["command"] = [str(paths.gemini_bin)]
    by_name["gemini"]["enabled"] = True
    by_name["rooter"]["enabled"] = False
    config["agents"] = [by_name["gemini"], by_name["rooter"]]
    config["auto_dispatch"] = True
    config["job_queue_root"] = str(paths.job_queue_root)
    config["worker_root"] = str(paths.worker_root)
    config["worker_uid"] = int(paths.worker_uid)
    config["gemini_bin"] = str(paths.gemini_bin)
    config["gemini_model"] = model
    config["gemini_admin_policy"] = str(paths.policy)
    config["publisher_allowed_repositories"] = sorted(repositories)
    config["publisher_known_hosts"] = str(paths.known_hosts)
    config["max_auto_attempts"] = int(config.get("max_auto_attempts", 3))
    config["worker_timeout_seconds"] = int(config.get("worker_timeout_seconds", 900))
    config["retry_cooldown_seconds"] = max(1800, int(config.get("retry_cooldown_seconds", 1800)))
    try:
        _write_atomic_config(config_path, config)
    except OSError:
        return _fail("atomic config update failed")
    return 0


def _discover_gemini_bin() -> Path:
    explicit = os.environ.get("CONTINUITY_GEMINI_BIN")
    if explicit:
        return Path(explicit)
    discovered = shutil.which("gemini")
    if discovered:
        return Path(discovered)
    candidates = sorted(Path("/opt").glob("node-v*/bin/gemini"), reverse=True)
    return candidates[0] if candidates else Path("/usr/local/bin/gemini")


def _production_paths() -> GatePaths:
    worker = pwd.getpwnam("agent-continuity-worker")
    worker_group = grp.getgrnam("agent-continuity-worker")
    return GatePaths(
        runtime_sha=Path("/opt/agent-continuity/.source-sha"),
        worker_unit=Path("/etc/systemd/system/agent-continuity-worker.service"),
        worker_path_unit=Path("/etc/systemd/system/agent-continuity-worker.path"),
        publisher_unit=Path("/etc/systemd/system/agent-continuity-publisher.service"),
        publisher_path_unit=Path("/etc/systemd/system/agent-continuity-publisher.path"),
        gemini_env=Path("/etc/agent-continuity/gemini.env"),
        publisher_dir=Path("/etc/agent-continuity/publisher"),
        publisher_key=Path("/etc/agent-continuity/publisher/id_ed25519"),
        known_hosts=Path("/etc/agent-continuity/publisher-known-hosts"),
        gemini_bin=_discover_gemini_bin(),
        policy=Path("/etc/agent-continuity/gemini-admin-policy.toml"),
        worker_uid=worker.pw_uid,
        worker_gid=worker_group.gr_gid,
        job_queue_root=Path("/var/lib/agent-continuity/jobs"),
        worker_root=Path("/srv/continuity/worktrees"),
    )


def _unit_enabled(unit: str) -> bool:
    return subprocess.run(
        ["systemctl", "is-enabled", "--quiet", unit],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False,
    ).returncode == 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="enable-continuity-auto-dispatch")
    parser.add_argument("--config", default="/etc/agent-continuity/config.json")
    parser.add_argument("--pilot-evidence", default="/var/lib/agent-continuity/pilot-evidence.json")
    parser.add_argument("--source-sha", required=True)
    args = parser.parse_args(argv)
    if os.geteuid() != 0:
        return _fail("production enablement must run as root")
    try:
        pilot = json.loads(Path(args.pilot_evidence).read_text(encoding="utf-8"))
        paths = _production_paths()
    except (OSError, KeyError, json.JSONDecodeError):
        return _fail("production preflight evidence is unavailable")
    rc = enable(
        Path(args.config), pilot=pilot, source_sha=args.source_sha,
        paths=paths, unit_enabled=_unit_enabled,
    )
    if rc == 0:
        print("CONTINUITY_AUTO_DISPATCH_ENABLED=true")
    return rc


if __name__ == "__main__":
    raise SystemExit(main())
