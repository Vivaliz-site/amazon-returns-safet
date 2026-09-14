from __future__ import annotations

import argparse
from dataclasses import asdict
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import shutil
import sys
import hashlib
from typing import Any

from .controller import Controller, RepoConfig
from .dispatcher import AgentCandidate, Dispatcher
from .git_scan import scan_repository
from .github_state import GitHubStateReader
from .ledger import Ledger, TaskNotFound
from .resume_packet import render_resume_packet

UTC = timezone.utc
DEFAULT_CONFIG = "/etc/agent-continuity/config.json"


def _load_config(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def _repo_configs(config: dict[str, Any]) -> list[RepoConfig]:
    return [RepoConfig.from_dict(item) for item in config.get("repositories", [])]


def _print_json(value: Any) -> None:
    print(json.dumps(value, ensure_ascii=True, sort_keys=True, indent=2))


def _task_dict(task) -> dict[str, Any]:
    return task.as_public_dict()


def _agent_candidates(config: dict[str, Any]) -> list[AgentCandidate]:
    result: list[AgentCandidate] = []
    for item in config.get("agents", []):
        command = [str(part) for part in item.get("command", [])]
        result.append(AgentCandidate(str(item.get("name", "")), command, bool(item.get("enabled", False))))
    return result


def _agent_availability(candidates: list[AgentCandidate]) -> dict[str, bool]:
    result: dict[str, bool] = {}
    for candidate in candidates:
        if not candidate.command:
            result[candidate.name.lower()] = False
            continue
        executable = candidate.command[0]
        available = Path(executable).exists() if Path(executable).is_absolute() else shutil.which(executable) is not None
        result[candidate.name.lower()] = bool(candidate.enabled and available)
    return result


def _dispatch_dict(decision) -> dict[str, Any] | None:
    if decision is None:
        return None
    return {
        "task_id": decision.task_id,
        "agent": decision.agent.name,
        "claimed": decision.claimed,
        "launched": decision.launched,
        "session_id": decision.session_id,
        "resume_packet_sha256": hashlib.sha256(decision.resume_packet.encode("utf-8")).hexdigest(),
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="agent-continuity")
    parser.add_argument("--config", default=os.environ.get("CONTINUITY_CONFIG", DEFAULT_CONFIG))
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("init")
    for name in ("scan", "queue", "reconcile"):
        p = sub.add_parser(name)
        p.add_argument("--json", action="store_true")
        if name == "reconcile":
            p.add_argument("--audit-only", action="store_true", default=False)
    show = sub.add_parser("show")
    show.add_argument("task_id")
    show.add_argument("--json", action="store_true")
    claim = sub.add_parser("claim")
    claim.add_argument("task_id")
    claim.add_argument("--agent", required=True)
    claim.add_argument("--session", required=True)
    claim.add_argument("--lease-seconds", type=int, default=1800)
    heartbeat = sub.add_parser("heartbeat")
    heartbeat.add_argument("task_id")
    heartbeat.add_argument("--session", required=True)
    heartbeat.add_argument("--lease-seconds", type=int, default=1800)
    packet = sub.add_parser("resume-packet")
    packet.add_argument("task_id")
    associate = sub.add_parser("associate")
    associate.add_argument("orphan_id")
    associate.add_argument("task_id")
    associate.add_argument("--objective", required=True)
    associate.add_argument("--json", action="store_true")
    dispatch = sub.add_parser("dispatch")
    dispatch.add_argument("--json", action="store_true")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    config_path = Path(args.config)
    config = _load_config(config_path)
    ledger = Ledger(Path(config["ledger_path"]))
    repos = _repo_configs(config)

    if args.command == "init":
        print(str(ledger.path))
        return 0
    if args.command == "scan":
        rows = [asdict(scan_repository(repo.path, repo.base_ref)) for repo in repos]
        if args.json:
            _print_json(rows)
        else:
            for row in rows:
                print(f"{row['repo']} branch={row['branch']} dirty={bool(row['modified'] or row['staged'] or row['untracked'])}")
        return 0
    if args.command == "queue":
        rows = [_task_dict(task) for task in ledger.list_resume_queue()]
        if args.json:
            _print_json(rows)
        else:
            for row in rows:
                print(f"{row['task_id']} {row['classification']} priority={row['priority']} {row['next_action'] or ''}")
        return 0
    if args.command == "show":
        task = ledger.get_task(args.task_id)
        if not task:
            raise TaskNotFound(args.task_id)
        if args.json:
            _print_json(_task_dict(task))
        else:
            print(json.dumps(_task_dict(task), ensure_ascii=True, sort_keys=True, indent=2))
        return 0
    if args.command == "claim":
        task = ledger.claim(args.task_id, args.agent, args.session, datetime.now(tz=UTC), args.lease_seconds)
        _print_json(_task_dict(task))
        return 0
    if args.command == "heartbeat":
        task = ledger.heartbeat(args.task_id, args.session, datetime.now(tz=UTC), args.lease_seconds)
        _print_json(_task_dict(task))
        return 0
    if args.command == "dispatch":
        candidates = _agent_candidates(config)
        dispatcher = Dispatcher(
            ledger, candidates,
            auto_dispatch=bool(config.get("auto_dispatch", False)),
            availability=_agent_availability(candidates),
            lease_seconds=int(config.get("lease_seconds", 1800)),
            retry_cooldown_seconds=int(config.get("retry_cooldown_seconds", 1800)),
        )
        decision = dispatcher.claim_next(datetime.now(tz=UTC))
        payload = _dispatch_dict(decision)
        if args.json:
            _print_json(payload)
        elif payload is None:
            print("no dispatchable task or available agent")
        else:
            mode = "launched" if payload["launched"] else "preview"
            print(f"{mode} {payload['task_id']} agent={payload['agent']}")
        return 0
    if args.command == "associate":
        task = ledger.associate_orphan(
            args.orphan_id, args.task_id, args.objective, datetime.now(tz=UTC)
        )
        if args.json:
            _print_json(_task_dict(task))
        else:
            print(f"{task.task_id} -> {task.worktree_path}")
        return 0
    if args.command == "resume-packet":
        task = ledger.get_task(args.task_id)
        if not task:
            raise TaskNotFound(args.task_id)
        finding = scan_repository(Path(task.worktree_path))
        remote = None
        repo_config = next((item for item in repos if item.repository == task.repository), None)
        if repo_config and repo_config.github_enabled:
            remote = GitHubStateReader().branch_state(task.repository, task.branch, repo_config.base_branch)
        print(render_resume_packet(task, finding, remote), end="")
        return 0
    if args.command == "reconcile":
        controller = Controller(ledger)
        reports = [controller.reconcile_repository(repo, audit_only=args.audit_only) for repo in repos]
        rows = [report.as_dict() for report in reports]
        if args.json:
            _print_json(rows)
        else:
            for row in rows:
                print(f"{row['repository']} {row['classification'] or 'CLEAN'} {row['task_id'] or '-'}")
        return 0
    return 2


if __name__ == "__main__":
    sys.exit(main())
