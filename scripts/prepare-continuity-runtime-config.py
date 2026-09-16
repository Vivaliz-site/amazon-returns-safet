#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import stat
import tempfile

DEFAULTS = {
    "job_queue_root": "/var/lib/agent-continuity/jobs",
    "worker_root": "/srv/continuity/worktrees",
    "gemini_admin_policy": "/etc/agent-continuity/gemini-admin-policy.toml",
    "gemini_model": "gemini-3.1-flash-lite",
    "publisher_known_hosts": "/etc/agent-continuity/publisher-known-hosts",
    "max_auto_attempts": 3,
    "worker_timeout_seconds": 900,
}


def _atomic_write(path: Path, payload: dict) -> None:
    st = path.stat()
    fd, raw = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temp = Path(raw)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, stat.S_IMODE(st.st_mode))
        if os.geteuid() == 0:
            os.chown(temp, st.st_uid, st.st_gid)
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


def prepare(path: Path, *, worker_uid: int, gemini_bin: str | None = None) -> bool:
    path = Path(path)
    data = json.loads(path.read_text(encoding="utf-8"))
    if data.get("auto_dispatch") is not False:
        raise ValueError("runtime config preparation requires auto_dispatch=false")
    desired = dict(DEFAULTS)
    desired["worker_uid"] = int(worker_uid)
    if gemini_bin:
        desired["gemini_bin"] = str(Path(gemini_bin))
    repositories = sorted({
        str(item.get("repository"))
        for item in data.get("repositories", [])
        if isinstance(item, dict) and item.get("repository")
    })
    desired["publisher_allowed_repositories"] = repositories
    desired["retry_cooldown_seconds"] = max(1800, int(data.get("retry_cooldown_seconds", 1800)))
    changed = any(data.get(key) != value for key, value in desired.items())
    if not changed:
        return False
    data.update(desired)
    _atomic_write(path, data)
    return True


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="prepare-continuity-runtime-config")
    parser.add_argument("config")
    parser.add_argument("--worker-uid", type=int, required=True)
    parser.add_argument("--gemini-bin")
    args = parser.parse_args(argv)
    try:
        changed = prepare(Path(args.config), worker_uid=args.worker_uid, gemini_bin=args.gemini_bin)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"continuity runtime config preparation refused: {exc}", file=__import__('sys').stderr)
        return 2
    print(f"CONTINUITY_RUNTIME_CONFIG_PREPARED={'true' if changed else 'unchanged'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
