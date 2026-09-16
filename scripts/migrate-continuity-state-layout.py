#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import stat
import sys
import tempfile


class StateLayoutMigrationError(ValueError):
    pass


def _atomic_write_config(path: Path, payload: dict) -> None:
    current = path.stat()
    fd, raw = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temp = Path(raw)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, stat.S_IMODE(current.st_mode))
        if os.geteuid() == 0:
            os.chown(temp, current.st_uid, current.st_gid)
        os.replace(temp, path)
    finally:
        temp.unlink(missing_ok=True)


def migrate(config_path: Path, *, state_root: Path, controller_state: Path) -> bool:
    config_path = Path(config_path)
    state_root = Path(state_root).resolve()
    controller_state = Path(controller_state).resolve()
    payload = json.loads(config_path.read_text(encoding="utf-8"))
    legacy = state_root / "ledger.sqlite3"
    target = controller_state / "ledger.sqlite3"
    configured = Path(str(payload.get("ledger_path", ""))).resolve()
    if configured not in {legacy, target}:
        raise StateLayoutMigrationError("refusing unknown continuity ledger location")

    legacy_artifacts = [legacy, Path(str(legacy) + "-wal"), Path(str(legacy) + "-shm")]
    target_artifacts = [target, Path(str(target) + "-wal"), Path(str(target) + "-shm")]
    legacy_present = any(path.exists() for path in legacy_artifacts)
    target_present = any(path.exists() for path in target_artifacts)
    if legacy_present and not legacy.is_file():
        raise StateLayoutMigrationError("legacy ledger sidecars exist without ledger")
    if target_present and not target.is_file():
        raise StateLayoutMigrationError("target ledger sidecars exist without ledger")
    if legacy_present and target_present:
        raise StateLayoutMigrationError("both legacy and target ledgers exist")

    if configured == target and not legacy_present:
        return False
    if configured == legacy and target_present:
        raise StateLayoutMigrationError("target ledger exists while config still points at legacy")
    if payload.get("auto_dispatch") is True:
        raise StateLayoutMigrationError("refusing continuity state migration while auto_dispatch=true")

    controller_state.mkdir(parents=True, exist_ok=True)
    if not legacy_present:
        payload["ledger_path"] = str(target)
        _atomic_write_config(config_path, payload)
        return True

    moves: list[tuple[Path, Path]] = []
    for source in legacy_artifacts:
        if source.exists():
            moves.append((source, controller_state / source.name))
    moved: list[tuple[Path, Path]] = []
    try:
        for source, destination in moves:
            if destination.exists():
                raise StateLayoutMigrationError(f"target already exists: {destination}")
            os.replace(source, destination)
            moved.append((source, destination))
        if configured != target:
            payload["ledger_path"] = str(target)
            _atomic_write_config(config_path, payload)
    except Exception:
        for source, destination in reversed(moved):
            if destination.exists() and not source.exists():
                os.replace(destination, source)
        raise
    return True


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="migrate-continuity-state-layout")
    parser.add_argument("--config", required=True)
    parser.add_argument("--state-root", required=True)
    parser.add_argument("--controller-state", required=True)
    args = parser.parse_args(argv)
    try:
        migrate(
            Path(args.config),
            state_root=Path(args.state_root),
            controller_state=Path(args.controller_state),
        )
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"continuity state migration failed: {exc}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
