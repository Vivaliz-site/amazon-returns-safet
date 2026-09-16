#!/usr/bin/env python3
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import sys
import tempfile
import time
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from tools.continuity.controller import Controller, RepoConfig
from tools.continuity.dispatcher import _atomic_write_packet
from tools.continuity.git_scan import scan_repository
from tools.continuity.github_state import GitHubStateReader
from tools.continuity.job_queue import JobEnvelope, QueuePaths, atomic_write_job, load_receipt
from tools.continuity.ledger import Ledger
from tools.continuity.model import Classification, TaskRecord, TaskStatus
from tools.continuity.resume_packet import redact, render_resume_packet
from tools.continuity.worktrees import WorktreeSafetyError, assert_worker_owned_worktree

UTC = timezone.utc
REPOSITORY = "Vivaliz-site/amazon-returns-safet"
PUBLIC_REMOTE = "https://github.com/Vivaliz-site/amazon-returns-safet.git"
WORKER_USER = "agent-continuity-worker"
PUBLISHER_USER = "agent-continuity-publisher"
RUNTIME_ROOT = Path("/opt/agent-continuity/current")
RUNTIME_SHA = Path("/opt/agent-continuity/.source-sha")
DEFAULT_CONFIG = Path("/etc/agent-continuity/config.json")
DEFAULT_EVIDENCE = Path("/var/lib/agent-continuity/pilot-evidence.json")
PUBLISHER_PROOF = Path("/etc/agent-continuity/publisher/deploy-key.json")
PUBLISHER_KEY = Path("/etc/agent-continuity/publisher/id_ed25519")
GEMINI_ENV = Path("/etc/agent-continuity/gemini.env")
LEGACY_PATH = Path("/home/ubuntu/amazon-returns-deploy-source")
POLL_SECONDS = 10
POLL_LIMIT = 90


class PilotError(RuntimeError):
    pass


def _run(args: list[str], *, check: bool = True, capture: bool = True) -> subprocess.CompletedProcess[str]:
    cp = subprocess.run(args, text=True, capture_output=capture)
    if check and cp.returncode != 0:
        detail = (cp.stderr or cp.stdout or "command failed").strip()[:1200]
        raise PilotError(f"command failed: {args[0]}: {detail}")
    return cp


def _git(cwd: Path, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
    return _run(["git", "-c", f"safe.directory={cwd.resolve()}", "-C", str(cwd), *args], check=check)


def _write_json_atomic(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, raw = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temp = Path(raw)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle, sort_keys=True, indent=2)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.chmod(temp, 0o600)
        os.replace(temp, path)
    finally:
        try:
            temp.unlink()
        except FileNotFoundError:
            pass


def _read_json(path: Path) -> dict:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise PilotError(f"expected JSON object: {path}")
    return value


def _is_enabled(unit: str) -> bool:
    return _run(["systemctl", "is-enabled", "--quiet", unit], check=False).returncode == 0


def _user_can_read(user: str, path: Path) -> bool:
    return _run(["runuser", "-u", user, "--", "test", "-r", str(path)], check=False).returncode == 0


def _worker_python(code: str, *args: str) -> subprocess.CompletedProcess[str]:
    return _run([
        "runuser", "-u", WORKER_USER, "--", "env",
        f"PYTHONPATH={RUNTIME_ROOT}",
        f"HOME=/var/lib/{WORKER_USER}",
        "python3", "-c", code, *args,
    ])


def _worker_git(worktree: Path, *args: str) -> subprocess.CompletedProcess[str]:
    return _run([
        "runuser", "-u", WORKER_USER, "--", "env",
        f"HOME=/var/lib/{WORKER_USER}",
        "git", "-c", f"safe.directory={worktree}", "-C", str(worktree), *args,
    ])


def _prepare_worker_worktree(config: dict, task_id: str, source_sha: str) -> tuple[Path, str, str]:
    worker_root = Path(str(config["worker_root"]))
    base_root = worker_root.parent
    code = r'''
import json, os, sys
from pathlib import Path
from tools.continuity.worktrees import prepare_worker_source, create_worker_task_worktree
remote, source_root, base_root, repository, task_id, uid = sys.argv[1:]
source = prepare_worker_source(remote, Path(source_root), repository)
result = create_worker_task_worktree(source, Path(base_root), task_id, "continuity-recovery", "origin/main", int(uid))
print(json.dumps({"source": str(source), "path": str(result.path), "branch": result.branch, "head": result.head}))
'''
    cp = _worker_python(
        code, PUBLIC_REMOTE, str(base_root / "sources"), str(base_root),
        REPOSITORY, task_id, str(config["worker_uid"]),
    )
    payload = json.loads(cp.stdout.strip().splitlines()[-1])
    if payload["head"] != source_sha:
        raise PilotError("worker source origin/main does not match deployed source SHA")
    return Path(payload["path"]), str(payload["branch"]), str(payload["source"])


def _preflight(config_path: Path) -> tuple[dict, str, dict]:
    if os.geteuid() != 0:
        raise PilotError("pilot must run as root")
    config = _read_json(config_path)
    if config.get("auto_dispatch") is not False:
        raise PilotError("pilot requires auto_dispatch=false")
    source_sha = _git(ROOT, "rev-parse", "HEAD").stdout.strip()
    runtime_sha = RUNTIME_SHA.read_text(encoding="utf-8").strip()
    if runtime_sha != source_sha:
        raise PilotError("runtime_sha does not match source_sha")
    remote_main = _run(["git", "ls-remote", PUBLIC_REMOTE, "refs/heads/main"]).stdout.split()[0]
    if remote_main != source_sha:
        raise PilotError("origin/main does not match deployed source_sha")
    for unit in ("agent-continuity-worker.path", "agent-continuity-publisher.path"):
        if not _is_enabled(unit):
            raise PilotError(f"required path unit is not enabled: {unit}")
    for unit in ("agent-continuity-worker.service", "agent-continuity-publisher.service"):
        if not Path(f"/etc/systemd/system/{unit}").is_file():
            raise PilotError(f"required service is not installed: {unit}")
    required = (
        GEMINI_ENV,
        PUBLISHER_KEY,
        PUBLISHER_PROOF,
        Path(str(config["publisher_known_hosts"])),
        Path(str(config["gemini_admin_policy"])),
        Path(str(config["gemini_bin"])),
    )
    if not all(path.is_file() for path in required):
        missing = [str(path) for path in required if not path.is_file()]
        raise PilotError(f"pilot credential/runtime preflight missing: {missing}")
    if not os.access(Path(str(config["gemini_bin"])), os.X_OK):
        raise PilotError("Gemini CLI is not executable")
    proof = _read_json(PUBLISHER_PROOF)
    if proof.get("repository") != REPOSITORY or proof.get("read_only") is not False:
        raise PilotError("publisher deploy-key proof is not write-enabled for expected repository")
    if not _user_can_read(WORKER_USER, GEMINI_ENV):
        raise PilotError("worker cannot read dedicated Gemini environment")
    worker_reads_publisher = _user_can_read(WORKER_USER, PUBLISHER_KEY)
    publisher_reads_gemini = _user_can_read(PUBLISHER_USER, GEMINI_ENV)
    secret_boundary = not worker_reads_publisher and not publisher_reads_gemini
    if not secret_boundary:
        raise PilotError("secret_boundary failed between Gemini worker and publisher")
    _run([
        "runuser", "-u", WORKER_USER, "--", "env",
        f"HOME=/var/lib/{WORKER_USER}", str(config["gemini_bin"]), "--version",
    ])
    signal = (ROOT / ".github/workflows/continuity-signal.yml").read_text(encoding="utf-8")
    publisher_workflow = (ROOT / ".github/workflows/continuity-publisher.yml").read_text(encoding="utf-8")
    if "contents: read" not in signal or "workflow_run:" not in publisher_workflow:
        raise PilotError("GitHub Actions publication contract is missing")
    if "actions/checkout" in publisher_workflow:
        raise PilotError("privileged publisher workflow must not checkout untrusted branch code")
    try:
        assert_worker_owned_worktree(LEGACY_PATH, Path(str(config["worker_root"])), int(config["worker_uid"]))
    except WorktreeSafetyError:
        legacy_rejected = True
    else:
        legacy_rejected = False
    if not legacy_rejected:
        raise PilotError("legacy path was not rejected by worker ownership boundary")
    queue_root = Path(str(config["job_queue_root"]))
    receipt_dir = queue_root / "receipts"
    marker_dir = queue_root.parent / "publisher"
    pending_receipts = [
        path for path in receipt_dir.glob("*.json")
        if not (marker_dir / f"{path.name}.done.json").exists()
    ] if receipt_dir.exists() else []
    if pending_receipts:
        raise PilotError("unprocessed worker receipts exist before pilot")
    return config, source_sha, {
        "runtime_sha": runtime_sha,
        "secret_boundary": secret_boundary,
        "legacy_path_rejected": legacy_rejected,
    }


def _pilot_objective(task_id: str, fixture: str) -> str:
    return (
        "Complete only the disposable continuity recovery pilot. "
        f"Modify only {fixture}. Replace its contents with exactly two lines: "
        f"CONTINUITY_PILOT=GEMINI_RESUMED and TASK_ID={task_id}. "
        f"Then run only bounded validation, git add {fixture}, and create one local commit "
        f"with message 'test: continuity recovery pilot {task_id}'. "
        "Do not push, do not edit .github/workflows, do not touch any other file, "
        "and finish with a clean git status."
    )


def _write_as_worker(path: Path, content: str) -> None:
    code = r'''
from pathlib import Path
import sys
path = Path(sys.argv[1])
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(sys.argv[2], encoding="utf-8")
'''
    _worker_python(code, str(path), content)


def _create_interrupted_task(config: dict, source_sha: str, task_id: str) -> dict:
    worktree, branch, source = _prepare_worker_worktree(config, task_id, source_sha)
    _worker_git(worktree, "config", "user.email", "continuity-pilot@shopvivaliz.invalid")
    _worker_git(worktree, "config", "user.name", "ShopVivaliz Continuity Pilot")
    fixture = "tests/continuity/pilot-fixtures/last-success.txt"
    objective = _pilot_objective(task_id, fixture)
    ledger = Ledger(Path(str(config["ledger_path"])))
    created = datetime.now(tz=UTC) - timedelta(seconds=5)
    task = TaskRecord(
        task_id=task_id, repository=REPOSITORY, host="shopvivaliz-free-a1",
        worktree_path=str(worktree), branch=branch, base_sha=source_sha,
        current_head=source_sha, objective=objective,
        status=TaskStatus.NEEDS_RESUME, classification=Classification.NEEDS_RESUME,
        priority=-100000, next_action="simulate interrupted agent then resume with Gemini",
        created_at=created, updated_at=created,
    )
    ledger.create_task(task)
    session_a = f"pilot-{task_id}-session-a"
    ledger.claim(task_id, "pilot-interrupted", session_a, created, 1)
    _write_as_worker(
        worktree / fixture,
        f"CONTINUITY_PILOT=INTERRUPTED\nTASK_ID={task_id}\n",
    )
    finding = scan_repository(worktree)
    ledger.update_fields(
        task_id,
        dirty_files=list(finding.modified), staged_files=list(finding.staged),
        untracked_files=list(finding.untracked), patch_summary="pilot interruption fixture created",
        updated_at=datetime.now(tz=UTC),
    )
    expired = ledger.expire_leases(datetime.now(tz=UTC))
    lost = next((item for item in expired if item.task_id == task_id), None)
    if lost is None or lost.status is not TaskStatus.AGENT_LOST:
        raise PilotError("AGENT_LOST transition was not persisted")
    if not (worktree / fixture).exists():
        raise PilotError("interrupted fixture was not preserved at AGENT_LOST")

    report = Controller(ledger).reconcile_repository(
        RepoConfig(
            repository=REPOSITORY, host="shopvivaliz-free-a1", path=worktree,
            base_ref="origin/main", base_branch="main", github_enabled=False,
        ),
        audit_only=True,
    )
    resumed = ledger.get_task(task_id)
    if resumed is None or resumed.classification is not Classification.NEEDS_RESUME:
        raise PilotError("NEEDS_RESUME classification was not restored")
    if resumed.status is not TaskStatus.NEEDS_RESUME:
        raise PilotError("NEEDS_RESUME status was not restored after AGENT_LOST")
    if (worktree / fixture).read_text(encoding="utf-8").splitlines()[0] != "CONTINUITY_PILOT=INTERRUPTED":
        raise PilotError("interrupted worktree contents changed during reconciliation")
    return {
        "ledger": ledger,
        "worktree": worktree,
        "source": Path(source),
        "branch": branch,
        "fixture": fixture,
        "session_a": session_a,
        "lost_status": lost.status.value,
        "resumed_status": resumed.status.value,
        "reconcile_classification": report.classification,
    }


def _enqueue_gemini_resume(config: dict, state: dict, task_id: str) -> dict:
    ledger: Ledger = state["ledger"]
    worktree: Path = state["worktree"]
    now = datetime.now(tz=UTC)
    session_b = f"pilot-{task_id}-gemini-session-b"
    claimed = ledger.claim(task_id, "gemini", session_b, now, 1800)
    finding = scan_repository(worktree)
    packet = render_resume_packet(claimed, finding, None)
    queue = QueuePaths.under(Path(str(config["job_queue_root"])))
    job_id = f"{task_id}--{session_b}"
    packet_path = _atomic_write_packet(queue, job_id, packet)
    job = JobEnvelope(
        task_id=task_id, repository=REPOSITORY, worktree_path=str(worktree),
        branch=str(state["branch"]), expected_head=finding.head,
        base_sha=claimed.base_sha, lease_session_id=session_b, provider="gemini",
        resume_packet_sha256=hashlib.sha256(packet.encode("utf-8")).hexdigest(),
        resume_packet_path=str(packet_path), created_at=now.isoformat(),
        deadline_at=claimed.lease_expires_at.isoformat(),
    )
    job_path = atomic_write_job(queue, job)
    if (job_path.stat().st_mode & 0o777) != 0o640 or (packet_path.stat().st_mode & 0o777) != 0o640:
        raise PilotError("shared queue artifacts are not group-readable 0640")
    service = _run(["systemctl", "start", "agent-continuity-worker.service"], check=False)
    receipt_path = queue.receipts / f"{task_id}--{session_b}.json"
    if not receipt_path.is_file():
        detail = (service.stderr or service.stdout or "worker produced no receipt").strip()[:1000]
        raise PilotError(f"Gemini worker produced no receipt: {detail}")
    receipt = load_receipt(receipt_path)
    if receipt.status != "completed":
        raise PilotError(f"GEMINI worker did not complete: {receipt.diagnostics[:600]}")
    if service.returncode != 0:
        raise PilotError("Gemini worker service returned nonzero after completed receipt")

    status = _worker_git(worktree, "status", "--porcelain").stdout.strip()
    if status:
        raise PilotError("Gemini worker left a dirty worktree")
    changed = [
        line for line in _worker_git(worktree, "diff", "--name-only", f"{claimed.base_sha}..{receipt.resulting_head}").stdout.splitlines()
        if line.strip()
    ]
    if changed != [state["fixture"]]:
        raise PilotError(f"Gemini worker changed unexpected paths: {changed}")
    count = int(_worker_git(worktree, "rev-list", "--count", f"{claimed.base_sha}..{receipt.resulting_head}").stdout.strip())
    if count != 1:
        raise PilotError("Gemini worker did not create exactly one pilot commit")
    expected = f"CONTINUITY_PILOT=GEMINI_RESUMED\nTASK_ID={task_id}\n"
    if (worktree / state["fixture"]).read_text(encoding="utf-8") != expected:
        raise PilotError("Gemini worker did not produce deterministic pilot fixture")
    ledger.update_fields(
        task_id,
        current_head=receipt.resulting_head,
        status=TaskStatus.NEEDS_RESUME,
        classification=Classification.NEEDS_RESUME,
        agent_session_id=None,
        lease_expires_at=None,
        dirty_files=[], staged_files=[], untracked_files=[],
        local_test_results=["GEMINI worker receipt: completed", "worktree clean: PASS"],
        next_action="publish exact pilot head through isolated publisher",
        updated_at=datetime.now(tz=UTC),
    )
    ledger.record_dispatch_attempt(task_id, "gemini", now, "worker_completed", str(receipt_path))
    return {
        "session_b": session_b,
        "receipt_path": receipt_path,
        "head": receipt.resulting_head,
        "model": receipt.model,
        "input_tokens": receipt.input_tokens,
        "output_tokens": receipt.output_tokens,
        "cached_tokens": receipt.cached_tokens,
    }


def _public_pr_detail(repository: str, number: int) -> dict:
    request = Request(
        f"https://api.github.com/repos/{repository}/pulls/{number}",
        headers={
            "Accept": "application/vnd.github+json",
            "User-Agent": "shopvivaliz-continuity-pilot/1",
            "X-GitHub-Api-Version": "2022-11-28",
        },
        method="GET",
    )
    with urlopen(request, timeout=20) as response:
        payload = json.load(response)
    if not isinstance(payload, dict):
        raise PilotError("GitHub PR detail response is not an object")
    return payload


def _publish_and_wait(config: dict, state: dict, worker: dict, task_id: str) -> dict:
    receipt_path: Path = worker["receipt_path"]
    service = _run(["systemctl", "start", "agent-continuity-publisher.service"], check=False)
    marker = Path(str(config["job_queue_root"])).parent / "publisher" / f"{receipt_path.name}.done.json"
    if not marker.is_file():
        detail = (service.stderr or service.stdout or "publisher produced no marker").strip()[:1000]
        raise PilotError(f"publisher produced no marker: {detail}")
    publication = _read_json(marker)
    if publication.get("classification") != "published":
        raise PilotError(f"publisher did not publish exact pilot head: {publication}")
    if publication.get("head") != worker["head"]:
        raise PilotError("publisher marker head differs from Gemini result head")

    reader = GitHubStateReader()
    remote = None
    for _ in range(POLL_LIMIT):
        remote = reader.branch_state(REPOSITORY, str(state["branch"]), "main")
        if remote.merged:
            break
        time.sleep(POLL_SECONDS)
    if remote is None or not remote.merged or not remote.pr_number:
        raise PilotError("PR did not reach MERGED within bounded polling")
    if remote.head_sha != worker["head"]:
        raise PilotError("merged PR head differs from exact published worker head")
    if str(remote.checks_state or "").lower() != "success":
        raise PilotError(f"CI did not finish green: {remote.checks_state}")
    detail = _public_pr_detail(REPOSITORY, int(remote.pr_number))
    merged_sha = str(detail.get("merge_commit_sha") or "")
    if len(merged_sha) != 40:
        raise PilotError("merged PR did not expose a valid merge_commit_sha")
    ledger: Ledger = state["ledger"]
    ledger.update_fields(
        task_id,
        pull_request=str(remote.pr_url or f"PR#{remote.pr_number}"),
        ci_state="success",
        status=TaskStatus.MERGED,
        classification=Classification.MERGED_UNVERIFIED,
        verification_state="VERIFIED",
        next_action="reconcile merged SHA and post-merge fixture",
        updated_at=datetime.now(tz=UTC),
    )
    report = Controller(ledger).reconcile_repository(
        RepoConfig(
            repository=REPOSITORY, host="shopvivaliz-free-a1", path=state["worktree"],
            base_ref="origin/main", base_branch="main", github_enabled=True,
        ),
        audit_only=True,
    )
    final_task = ledger.get_task(task_id)
    if final_task is None or final_task.classification is not Classification.DONE:
        raise PilotError(f"merged pilot did not reconcile to DONE: {report.classification}")
    return {
        "pr_number": int(remote.pr_number),
        "pr_url": str(remote.pr_url or ""),
        "ci_green": True,
        "merged_sha": merged_sha,
        "final_classification": final_task.classification.value,
    }


def _deploy_and_verify_merged(config: dict, state: dict, published: dict, task_id: str) -> dict:
    merged_sha = str(published["merged_sha"])
    deploy = _run(["systemctl", "start", "amazon-returns-deploy.service"], check=False)
    if deploy.returncode != 0:
        detail = (deploy.stderr or deploy.stdout or "deployment service failed").strip()[:1200]
        raise PilotError(f"post-merge deployment failed: {detail}")
    runtime_sha = RUNTIME_SHA.read_text(encoding="utf-8").strip()
    if runtime_sha != merged_sha:
        raise PilotError("post-merge runtime_sha does not equal merged_sha")
    local_main = _git(ROOT, "rev-parse", "HEAD").stdout.strip()
    if local_main != merged_sha:
        raise PilotError("deployment source checkout did not advance to merged_sha")
    remote_main = _run(["git", "ls-remote", PUBLIC_REMOTE, "refs/heads/main"]).stdout.split()[0]
    if remote_main != merged_sha:
        raise PilotError("origin/main does not equal merged_sha after publication")

    source: Path = state["source"]
    _run([
        "runuser", "-u", WORKER_USER, "--", "git", "--git-dir", str(source),
        "fetch", "--prune", "origin", "main",
    ])
    fixture = str(state["fixture"])
    shown = _run([
        "runuser", "-u", WORKER_USER, "--", "git", "--git-dir", str(source),
        "show", f"origin/main:{fixture}",
    ]).stdout
    expected = f"CONTINUITY_PILOT=GEMINI_RESUMED\nTASK_ID={task_id}\n"
    if shown != expected:
        raise PilotError("post-merge origin/main fixture verification failed")
    return {"runtime_sha": runtime_sha, "source_sha": merged_sha}


def _cleanup_local_pilot(state: dict) -> None:
    worktree: Path = state["worktree"]
    source: Path = state["source"]
    if _worker_git(worktree, "status", "--porcelain").stdout.strip():
        raise PilotError("refusing pilot cleanup because worktree is dirty")
    _run([
        "runuser", "-u", WORKER_USER, "--", "git", "--git-dir", str(source),
        "worktree", "remove", str(worktree),
    ])
    _run([
        "runuser", "-u", WORKER_USER, "--", "git", "--git-dir", str(source),
        "branch", "-D", str(state["branch"]),
    ], check=False)


def _safe_runtime_sha() -> str:
    try:
        return RUNTIME_SHA.read_text(encoding="utf-8").strip()
    except OSError:
        return ""


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="run-continuity-pilot")
    parser.add_argument("--config", default=str(DEFAULT_CONFIG))
    parser.add_argument("--evidence", default=str(DEFAULT_EVIDENCE))
    args = parser.parse_args(argv)
    evidence_path = Path(args.evidence)
    task_id = "PILOT-" + datetime.now(tz=UTC).strftime("%Y%m%d%H%M%S")
    pilot_base_sha = ""
    try:
        config, pilot_base_sha, preflight = _preflight(Path(args.config))
        state = _create_interrupted_task(config, pilot_base_sha, task_id)
        worker = _enqueue_gemini_resume(config, state, task_id)
        published = _publish_and_wait(config, state, worker, task_id)
        deployed = _deploy_and_verify_merged(config, state, published, task_id)
        _cleanup_local_pilot(state)
        evidence = {
            "status": "green",
            "repository": REPOSITORY,
            "task_id": task_id,
            "branch": state["branch"],
            "pilot_base_sha": pilot_base_sha,
            "source_sha": deployed["source_sha"],
            "runtime_sha": deployed["runtime_sha"],
            "session_a": state["session_a"],
            "session_b": worker["session_b"],
            "interruption_transition": "AGENT_LOST",
            "recovery_transition": "NEEDS_RESUME",
            "provider": "GEMINI",
            "model": worker["model"],
            "input_tokens": worker["input_tokens"],
            "output_tokens": worker["output_tokens"],
            "cached_tokens": worker["cached_tokens"],
            "pr_number": published["pr_number"],
            "pr_url": published["pr_url"],
            "ci_green": published["ci_green"],
            "merged_sha": published["merged_sha"],
            "final_classification": published["final_classification"],
            "secret_boundary": preflight["secret_boundary"],
            "legacy_path_rejected": preflight["legacy_path_rejected"],
            "completed_at": datetime.now(tz=UTC).isoformat(),
        }
        _write_json_atomic(evidence_path, evidence)
        print(json.dumps({"PILOT_STATUS": "GREEN", "task_id": task_id, "merged_sha": published["merged_sha"]}, sort_keys=True))
        return 0
    except Exception as exc:
        failure = redact(f"{type(exc).__name__}: {exc}")[:1200]
        evidence = {
            "status": "failed",
            "repository": REPOSITORY,
            "task_id": task_id,
            "pilot_base_sha": pilot_base_sha,
            "source_sha": _safe_runtime_sha(),
            "runtime_sha": _safe_runtime_sha(),
            "ci_green": False,
            "secret_boundary": False,
            "failure": failure,
            "failed_at": datetime.now(tz=UTC).isoformat(),
        }
        try:
            _write_json_atomic(evidence_path, evidence)
        except Exception:
            pass
        print(json.dumps({"PILOT_STATUS": "FAILED", "task_id": task_id, "reason": failure}, sort_keys=True), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
