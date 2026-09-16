# Recurring Gemini Worker Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Safely resume recurring continuity tasks with Gemini in worker-owned worktrees while keeping the controller read-only and forbidding automatic Codex/ChatGPT/Claude fallback.

**Architecture:** Keep `agent-continuity-controller` as the read-only authority for discovery, leases and queue ordering. Automatic dispatch writes a non-secret job envelope; a separate hardened `agent-continuity-worker` consumes it in `/srv/continuity/worktrees`, and a separate non-AI publisher handles GitHub publication with distinct credentials.

**Tech Stack:** Python 3.12 standard library, SQLite ledger, Git CLI, Gemini CLI 0.60+, systemd `.path`/oneshot services, GitHub CLI/API only inside the publisher trust boundary.

**Spec:** `docs/superpowers/specs/2026-09-16-recurring-gemini-worker-isolation-design.md`

## Global Constraints

- Automatic recurring agents are exactly `gemini` plus a future certified `rooter`; Codex/ChatGPT/Claude are forbidden for automatic dispatch.
- Production stays `auto_dispatch=false` until the disposable interruption/recovery pilot is green.
- Controller remains read-only against observed repositories and production deploy paths.
- Worker writes only beneath `/srv/continuity/worktrees` and worker state/result directories.
- No API key, token, cookie, private key, password, TOTP seed, or publisher credential may enter config JSON, ledger, job envelopes, resume packets, argv, commits, PRs, or journals.
- One automatic worker job per host; one dispatch attempt per controller cycle; retry cooldown is at least 1800 seconds.
- No destructive Git (`reset --hard`, `clean`, force checkout, force push) is permitted in automatic execution or rollback.
- `rooter` remains disabled until its executable, authentication, cost and sandbox contract are independently certified.

---
## File Structure

- `tools/continuity/job_queue.py` — immutable job/result dataclasses, atomic queue writes, safe-path checks, envelope validation and redaction.
- `tools/continuity/worker.py` — worker-side lease/path/head verification, bounded Gemini execution, timeout/process-group cleanup, Git post-state receipt.
- `tools/continuity/publisher.py` — non-AI exact-head branch push using only a repository-scoped deploy key.
- `.github/workflows/continuity-publisher.yml` — PR/check/auto-merge orchestration using GitHub Actions' ephemeral repository token; no persistent GitHub API token is stored on the host.
- `tools/continuity/github_state.py` — anonymous read-only public GitHub reconciliation when production has no host API token.
- `tools/continuity/dispatcher.py` — convert automatic dispatch from direct process launch into queue emission; manual preview remains unchanged.
- `tools/continuity/cli.py` — commands for worker/publisher execution and safe automation validation.
- `tools/continuity/worktrees.py` — worker-owned mirror/worktree preparation and ownership assertions.
- `deploy/continuity/gemini-admin-policy.toml` — deny-by-default recurring Gemini tool policy.
- `deploy/systemd/agent-continuity-worker.{path,service}` — isolated worker activation.
- `deploy/systemd/agent-continuity-publisher.{path,service}` — isolated publisher activation.
- `scripts/install-continuity-controller.sh` — install users, roots, runtime files and units while preserving operator-owned credentials/config.
- `scripts/enable-continuity-auto-dispatch.py` — idempotent gate that enables automation only after all runtime/pilot evidence passes.
- `scripts/run-continuity-pilot.sh` — disposable end-to-end interruption/recovery acceptance pilot.
- `docs/runbooks/agent-continuity-controller.md` — new automatic policy, worker/publisher operations and rollback.
- `tests/continuity/test_job_queue.py`, `test_worker.py`, `test_publisher.py`, `test_pilot_contract.py` — new security/lifecycle coverage.
- Existing continuity tests are modified only where their contracts legitimately change.

---

### Task 1: Non-secret job queue and result receipts

**Files:**
- Create: `tools/continuity/job_queue.py`
- Create: `tests/continuity/test_job_queue.py`

**Interfaces:**
- Produces: `JobEnvelope`, `WorkerReceipt`, `QueuePaths`, `validate_job(job, *, root, now)`, `atomic_write_job(...)`, `claim_pending_job(...)`, `atomic_write_receipt(...)`, `load_job(...)`.
- Consumes later: dispatcher, worker, publisher.
- [ ] **Step 1: Write failing queue security tests**

```python
class JobQueueTest(unittest.TestCase):
    def test_job_rejects_path_outside_worker_root(self):
        job = JobEnvelope.example(worktree_path="/home/ubuntu/amazon-returns-deploy-source")
        with self.assertRaises(JobValidationError):
            validate_job(job, root=Path("/srv/continuity/worktrees"), now=NOW)

    def test_job_json_never_contains_secret_like_fields(self):
        job = JobEnvelope.example()
        payload = job.to_json()
        for forbidden in ("token", "password", "secret", "cookie", "api_key", "private_key"):
            self.assertNotIn(forbidden, payload.lower())
```

- [ ] **Step 2: Run the tests and verify RED**

Run: `python3 -m unittest tests.continuity.test_job_queue -v`
Expected: FAIL because `tools.continuity.job_queue` does not exist.

- [ ] **Step 3: Implement immutable envelope/receipt and atomic writes**

```python
@dataclass(frozen=True)
class JobEnvelope:
    task_id: str
    repository: str
    worktree_path: str
    branch: str
    expected_head: str
    base_sha: str
    lease_session_id: str
    provider: str
    resume_packet_sha256: str
    resume_packet_path: str
    created_at: str
    deadline_at: str
```

Use `Path.resolve(strict=True)`, `os.open(..., O_CREAT|O_EXCL, 0o600)`, `fsync`, and atomic `os.replace`. Reject symlink escapes, unknown keys, forbidden provider names, expired jobs, unsafe task IDs, and any path not strictly beneath the configured worker root.
- [ ] **Step 4: Add negative-path coverage and verify GREEN**

Add tests for traversal (`../../`), symlink escape, unknown JSON fields, deadline expiration, provider=`codex`, provider=`claude`, malformed SHA/session IDs, secret-like JSON keys, duplicate filename collision, result receipt size limits, and two concurrent `claim_pending_job()` calls proving exactly one atomic pending→running rename succeeds.

Run: `python3 -m unittest tests.continuity.test_job_queue -v`
Expected: PASS with all queue/security tests green.

- [ ] **Step 5: Run adjacent security tests**

Run: `python3 -m unittest tests.continuity.test_resume_packet tests.continuity.test_ledger -v`
Expected: PASS; existing redaction and lease behavior unchanged.

- [ ] **Step 6: Commit**

```bash
git add tools/continuity/job_queue.py tests/continuity/test_job_queue.py
git commit -m "feat(continuity): add secure recurring job queue"
```

---

### Task 2: Worker-owned mirror/worktree preparation

**Files:**
- Modify: `tools/continuity/worktrees.py`
- Modify: `tests/continuity/test_worktrees.py`

**Interfaces:**
- Produces: `prepare_worker_source(repo_url, source_root, repository) -> Path`, `create_worker_task_worktree(source, worktree_root, task_id, slug, base_ref, worker_uid) -> WorktreeResult`, `assert_worker_owned_worktree(path, root, worker_uid) -> None`.
- Consumes: existing `_slug()` and `WorktreeSafetyError`.

- [ ] **Step 1: Write failing ownership/isolation tests**

```python
def test_worker_worktree_must_live_below_root_and_match_owner(self):
    with self.assertRaises(WorktreeSafetyError):
        assert_worker_owned_worktree(self.fx.repo, self.root, worker_uid=12345)

def test_worker_source_is_bare_and_task_path_is_unique(self):
    source = prepare_worker_source(self.remote, self.sources, "Vivaliz-site/amazon-returns-safet")
    self.assertTrue((source / "HEAD").exists())
    result = create_worker_task_worktree(source, self.root, "TASK-1", "pilot", "origin/main", os.getuid())
    self.assertTrue(result.path.is_relative_to(self.root / "worktrees"))
```
- [ ] **Step 2: Run worktree tests and verify RED**

Run: `python3 -m unittest tests.continuity.test_worktrees -v`
Expected: FAIL because worker-specific helpers are missing.

- [ ] **Step 3: Implement mirror and ownership guards**

Use a bare source at `/srv/continuity/sources/<owner>-<repo>.git`; never use `/home/ubuntu/amazon-returns-deploy-source` as the automatic worker source. Normalize repository names to `[A-Za-z0-9._-]`, reject symlinks in the root chain, require exact `st_uid == worker_uid`, and create worktrees only under `/srv/continuity/worktrees/<repo>/<task-id>`.

```python
def assert_worker_owned_worktree(path: Path, root: Path, worker_uid: int) -> None:
    resolved = path.resolve(strict=True)
    worker_root = root.resolve(strict=True)
    if resolved == worker_root or worker_root not in resolved.parents:
        raise WorktreeSafetyError("worktree outside worker root")
    if resolved.stat().st_uid != worker_uid:
        raise WorktreeSafetyError("worktree owner mismatch")
```

- [ ] **Step 4: Verify worktree tests GREEN**

Run: `python3 -m unittest tests.continuity.test_worktrees -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tools/continuity/worktrees.py tests/continuity/test_worktrees.py
git commit -m "feat(continuity): isolate worker-owned worktrees"
```

---

### Task 3: Dispatcher emits jobs instead of launching AI

**Files:**
- Modify: `tools/continuity/dispatcher.py`
- Modify: `tools/continuity/cli.py`
- Modify: `tests/continuity/test_dispatcher.py`
- Modify: `tests/continuity/test_cli.py`

**Interfaces:**
- Consumes: `QueuePaths`, `atomic_write_job`, `JobEnvelope`, `assert_worker_owned_worktree`.
- Produces: `DispatchDecision.queued: bool`, `job_id: str | None`; no direct recurring AI subprocess launch.
- [ ] **Step 1: Write failing dispatcher tests for queue-only automation**

```python
def test_auto_dispatch_queues_gemini_job_without_launcher(self):
    task = self.add_worker_owned_task()
    decision = self.dispatcher(auto_dispatch=True, availability={"gemini": True}).claim_next(self.now)
    self.assertTrue(decision.queued)
    self.assertFalse(decision.launched)
    self.assertEqual("gemini", decision.agent.name)
    self.assertTrue(self.paths.pending.joinpath(f"{decision.job_id}.json").exists())

def test_auto_dispatch_rejects_legacy_shared_worktree(self):
    self.add_task(worktree_path=str(self.fx.repo))
    self.assertIsNone(self.dispatcher(auto_dispatch=True, availability={"gemini": True}).claim_next(self.now))
```

- [ ] **Step 2: Run dispatcher/CLI tests and verify RED**

Run: `python3 -m unittest tests.continuity.test_dispatcher tests.continuity.test_cli -v`
Expected: FAIL because the dispatcher still invokes `launcher` directly.

- [ ] **Step 3: Replace automatic launcher with queue emission**

Manual preview (`auto_dispatch=false`) still renders command metadata only. Automatic mode must filter to `AUTOMATED_ALLOWED_AGENTS`, validate worktree ownership/head/lease, claim the lease, persist `dispatch_queued`, atomically write the resume packet and job, and return without executing Gemini.

```python
if self.auto_dispatch:
    envelope = self.job_factory(claimed, agent, finding, session_id, now)
    job_id = self.queue.write(envelope, packet)
    self.ledger.record_dispatch_attempt(task.task_id, agent.name, now, "dispatch_queued", job_id)
    return DispatchDecision(..., claimed=True, launched=False, queued=True, job_id=job_id)
```

- [ ] **Step 4: Add forbidden-agent and retry-budget regression assertions**

Assert that automatic selection returns `None` when only Codex/ChatGPT/Claude are available and that a queued envelope can never contain one of those provider names. Add `max_auto_attempts` (default `3`) to dispatcher config: when persisted recurring attempts for a task reach the limit, do not enqueue another job; update the task to `BLOCKED_EXTERNAL`, clear any expired lease, and persist `automatic retry budget exhausted` as the bounded reason. Verify the existing `retry_cooldown_seconds >= 1800` still suppresses immediate requeue after provider/worker failure.

- [ ] **Step 5: Verify dispatcher/CLI tests GREEN**

Run: `python3 -m unittest tests.continuity.test_dispatcher tests.continuity.test_cli -v`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tools/continuity/dispatcher.py tools/continuity/cli.py tests/continuity/test_dispatcher.py tests/continuity/test_cli.py
git commit -m "refactor(continuity): queue automatic Gemini work"
```
---

### Task 4: Hardened Gemini worker with timeout and receipts

**Files:**
- Create: `tools/continuity/worker.py`
- Create: `tests/continuity/test_worker.py`
- Create: `deploy/continuity/gemini-admin-policy.toml`

**Interfaces:**
- Consumes: pending `JobEnvelope`, read-only ledger evidence, worker root, Gemini env/model/policy.
- Produces: `WorkerRunResult`, `WorkerReceipt`, result file under `/var/lib/agent-continuity/results/worker/`.
- Entry point: `python3 -m tools.continuity.worker --config /etc/agent-continuity/config.json --once`.

- [ ] **Step 1: Write failing worker path/head/lease tests**

```python
def test_worker_rejects_head_mismatch_without_running_gemini(self):
    job = self.job(expected_head="0" * 40)
    result = run_one_job(self.ctx(job), runner=self.runner)
    self.assertEqual("rejected_head_mismatch", result.classification)
    self.assertEqual([], self.runner.calls)

def test_worker_rejects_expired_or_wrong_lease(self):
    job = self.job(lease_session_id="wrong-session")
    result = run_one_job(self.ctx(job), runner=self.runner)
    self.assertEqual("rejected_lease", result.classification)
```

- [ ] **Step 2: Write failing timeout/process-group test**

```python
def test_timeout_kills_process_group_and_writes_receipt(self):
    runner = SleepingProcessRunner()
    result = execute_gemini(self.context(timeout_seconds=1), runner=runner)
    self.assertEqual("timeout", result.classification)
    self.assertTrue(runner.process_group_killed)
    self.assertTrue(result.receipt_path.exists())
```

- [ ] **Step 3: Run worker tests and verify RED**

Run: `python3 -m unittest tests.continuity.test_worker -v`
Expected: FAIL because worker module/policy do not exist.
- [ ] **Step 4: Implement fail-closed worker validation**

Before any provider process: load exactly one oldest job, open ledger read-only, compare task ID/repository/path/branch/head/session/deadline, resolve ownership/root, reject Git operations in progress unless job explicitly records recovery, and verify dirty/untracked evidence is a subset of recorded task evidence.

- [ ] **Step 5: Implement bounded Gemini invocation**

```python
argv = [
    gemini_bin, "--model", model,
    "--output-format", "json",
    "--approval-mode", "yolo",
    "--admin-policy", str(policy_path),
    "--prompt", "",
]
proc = subprocess.Popen(argv, cwd=worktree, stdin=subprocess.PIPE,
                        stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                        text=True, start_new_session=True, env=worker_env)
```

`worker_env` contains only required runtime variables plus Gemini credential/model. It must explicitly remove `GH_TOKEN`, `GITHUB_TOKEN`, `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, `CLAUDE_API_KEY`, SSH agent variables and unrelated production credentials. On timeout, call `os.killpg(proc.pid, signal.SIGTERM)`, wait briefly, then `SIGKILL` if required.

- [ ] **Step 6: Implement deny-by-default Gemini admin policy**

Policy must allow project-local read/write and bounded validation/Git commands required to create a local commit, while denying privilege escalation, service management, access to `/home/ubuntu/amazon-returns-deploy*`, `/etc`, `/root`, credential files, destructive Git, persistence tooling, and arbitrary external publication. Worker systemd restrictions remain authoritative even if CLI policy parsing changes.

- [ ] **Step 7: Parse JSON output into token/accountability telemetry**

Record provider/model, start/end, exit classification, attempt number, task/session IDs, input/output/cached token counts when present, resulting head, changed paths and validation outcomes. Store no full prompt/output beyond bounded redacted diagnostics.

- [ ] **Step 8: Verify worker suite GREEN**

Run: `python3 -m unittest tests.continuity.test_worker tests.continuity.test_job_queue tests.continuity.test_resume_packet -v`
Expected: PASS, including secret-redaction and timeout tests.

- [ ] **Step 9: Commit**

```bash
git add tools/continuity/worker.py tests/continuity/test_worker.py deploy/continuity/gemini-admin-policy.toml
git commit -m "feat(continuity): add isolated Gemini worker"
```

---

### Task 5: Non-AI repository publisher

**Files:**
- Create: `tools/continuity/publisher.py`
- Create: `tests/continuity/test_publisher.py`
- Create: `.github/workflows/continuity-publisher.yml`
- Modify: `tools/continuity/github_state.py`
- Modify: `tools/continuity/controller.py`
- Modify: `tests/continuity/test_github_state.py`
- Modify: `tests/continuity/test_controller.py`

**Interfaces:**
- Consumes: successful `WorkerReceipt`, repository allowlist and repo-scoped SSH deploy key.
- Produces: local `PublishReceipt` proving exact-head branch push; GitHub Actions creates/reuses the PR and enables auto-merge with its ephemeral repository token.
- Production reconciliation reads the public repository/PR/check state without a host-side GitHub API credential.
- [ ] **Step 1: Write failing publisher isolation tests**

```python
def test_publisher_rejects_repo_outside_allowlist(self):
    with self.assertRaises(PublishSafetyError):
        publish_receipt(self.receipt(repository="other/repo"), self.cfg)

def test_publisher_environment_is_not_worker_environment(self):
    env = build_publisher_env(self.cfg)
    self.assertNotIn("GEMINI_API_KEY", env)
    self.assertNotIn("GH_TOKEN", env)
    self.assertIn("GIT_SSH_COMMAND", env)
```

- [ ] **Step 2: Write failing exact-head publication test**

```python
def test_publisher_refuses_when_receipt_head_differs_from_worktree_head(self):
    receipt = self.receipt(resulting_head="0" * 40)
    with self.assertRaises(PublishSafetyError):
        publish_receipt(receipt, self.cfg)
```

- [ ] **Step 3: Run publisher tests and verify RED**

Run: `python3 -m unittest tests.continuity.test_publisher -v`
Expected: FAIL because publisher module does not exist.
- [ ] **Step 4: Implement publisher safety checks and exact-head deploy-key push**

Publisher verifies repository allowlist, worker-owned worktree, exact receipt head, clean post-commit state, permitted branch prefix `agent/`, and receipt/job digest linkage. Push uses only the dedicated repository-scoped SSH deploy key; no GitHub API token is present on the host.

```python
def publish_receipt(receipt: WorkerReceipt, cfg: PublisherConfig) -> PublishReceipt:
    verify_publishable(receipt, cfg)
    verify_remote_branch_absent_or_same_sha(receipt, cfg)
    run_git(receipt.worktree_path, ["push", "origin", f"HEAD:refs/heads/{receipt.branch}"], env=build_publisher_env(cfg))
    return PublishReceipt.pushed(receipt)
```

Do not force-push. If the remote branch exists at another SHA, fail closed.

- [ ] **Step 5: Add GitHub Actions publisher and public reconciliation tests**

Create `.github/workflows/continuity-publisher.yml` triggered only by `agent/**` branch pushes with explicit `contents: write` and `pull-requests: write`. It creates/reuses the PR, waits for the normal CI contract, and requests auto-merge only after required checks succeed. Add `GitHubStateReader` public REST fallback using Python `urllib` when no host token exists; tests assert public reads require no credential and never perform writes.

- [ ] **Step 6: Verify publisher/public-state tests GREEN**

Run: `python3 -m unittest tests.continuity.test_publisher tests.continuity.test_github_state -v`
Expected: PASS. Also run a YAML parse/lint check used by repository CI for `.github/workflows/continuity-publisher.yml`.

- [ ] **Step 7: Commit**

```bash
git add tools/continuity/publisher.py tools/continuity/github_state.py tools/continuity/controller.py tests/continuity/test_publisher.py tests/continuity/test_github_state.py tests/continuity/test_controller.py .github/workflows/continuity-publisher.yml
git commit -m "feat(continuity): add repo-scoped publication path"
```

---

### Task 6: Separate systemd trust domains and installer

**Files:**
- Create: `deploy/systemd/agent-continuity-worker.path`
- Create: `deploy/systemd/agent-continuity-worker.service`
- Create: `deploy/systemd/agent-continuity-publisher.path`
- Create: `deploy/systemd/agent-continuity-publisher.service`
- Modify: `scripts/install-continuity-controller.sh`
- Create: `scripts/provision-continuity-gemini-env.sh`
- Create: `scripts/provision-continuity-publisher-key.sh`
- Modify: `tests/continuity/test_systemd_contract.py`
- Modify: `.github/workflows/ci.yml`
**Interfaces:**
- Worker identity: `agent-continuity-worker`; publisher identity: `agent-continuity-publisher`; controller remains `agent-continuity`.
- Worker env file: `/etc/agent-continuity/gemini.env`; publisher receives only a repository-scoped SSH deploy key through systemd `LoadCredential=` from `/etc/agent-continuity/publisher/id_ed25519` and has no persistent GitHub API token.

- [ ] **Step 1: Write failing systemd trust-boundary tests**

```python
def test_worker_and_publisher_are_separate_hardened_users(self):
    worker = Path("deploy/systemd/agent-continuity-worker.service").read_text()
    publisher = Path("deploy/systemd/agent-continuity-publisher.service").read_text()
    self.assertIn("User=agent-continuity-worker", worker)
    self.assertIn("EnvironmentFile=-/etc/agent-continuity/gemini.env", worker)
    self.assertNotIn("publisher", worker.lower())
    self.assertIn("User=agent-continuity-publisher", publisher)
    self.assertIn("LoadCredential=publisher_ssh_key:/etc/agent-continuity/publisher/id_ed25519", publisher)
    self.assertNotIn("GEMINI", publisher)
    self.assertNotIn("GH_TOKEN", publisher)
```

- [ ] **Step 2: Run systemd tests and verify RED**

Run: `python3 -m unittest tests.continuity.test_systemd_contract -v`
Expected: FAIL because worker/publisher units do not exist.

- [ ] **Step 3: Add hardened worker and publisher units**

Both services use `NoNewPrivileges=true`, `PrivateTmp=true`, `PrivateDevices=true`, `ProtectSystem=strict`, `ProtectHome=true`, kernel/control-group protections, `RestrictSUIDSGID=true`, `LockPersonality=true`, `UMask=0077`, `TasksMax`, `MemoryMax`, `CPUQuota`, and finite `TimeoutStartSec`.
Worker `ReadWritePaths` is limited to `/srv/continuity/sources`, `/srv/continuity/worktrees`, `/var/lib/agent-continuity/jobs`, and `/var/lib/agent-continuity/results/worker`. Publisher gets read-only access to worker receipts/worktrees plus only the minimal publication state path it needs. Neither service receives `/home/ubuntu/amazon-returns-deploy/shared/.env` or production deploy directories.

- [ ] **Step 4: Extend installer without overwriting secrets**

Create both system users if absent, create roots with explicit ownership/modes, install policy/runtime/unit files, and enable `.path` units only after files exist. Installer must fail if an existing credential file has unsafe ownership/mode; it never invents credential values. `provision-continuity-gemini-env.sh` runs as root, extracts only `GEMINI_API_KEY` plus the approved recurring model from the already protected AI secret source into `/etc/agent-continuity/gemini.env`, prints only `GEMINI_ENV_PROVISIONED=true`, and sets `0640 root:agent-continuity-worker`. `provision-continuity-publisher-key.sh` generates one Ed25519 key if absent, registers only its public half as a write-enabled deploy key on `Vivaliz-site/amazon-returns-safet`, verifies the returned key ID/repository, and stores the private half `0600 root:root` under `/etc/agent-continuity/publisher/`. The operator's broad GitHub login is used only during this one-time provisioning command and is never copied to recurring services.

```bash
install -d -m 0750 -o agent-continuity-worker -g agent-continuity-worker /srv/continuity/sources /srv/continuity/worktrees
install -d -m 0750 -o agent-continuity -g agent-continuity-worker /var/lib/agent-continuity/jobs/pending
install -d -m 0750 -o agent-continuity-worker -g agent-continuity /var/lib/agent-continuity/results/worker
```

- [ ] **Step 5: Extend CI syntax/contract checks**

Add `python3 -m py_compile tools/continuity/*.py`, `bash -n scripts/run-continuity-pilot.sh`, `bash -n scripts/provision-continuity-publisher-key.sh`, and systemd contract tests for both units/path files and secret separation.

- [ ] **Step 6: Verify systemd/installer suite GREEN**

Run: `python3 -m unittest tests.continuity.test_systemd_contract -v && bash -n scripts/install-continuity-controller.sh && bash -n scripts/provision-continuity-publisher-key.sh`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add deploy/systemd scripts/install-continuity-controller.sh scripts/provision-continuity-publisher-key.sh tests/continuity/test_systemd_contract.py .github/workflows/ci.yml
git commit -m "feat(continuity): isolate worker and publisher services"
```

---

### Task 7: Fail-closed production enablement gate

**Files:**
- Create: `scripts/enable-continuity-auto-dispatch.py`
- Create: `tests/continuity/test_enable_auto_dispatch.py`
- Modify: `deploy/continuity/config.production.json`
- Modify: `tools/continuity/config.example.json`
**Interfaces:**
- Command: `python3 scripts/enable-continuity-auto-dispatch.py --config /etc/agent-continuity/config.json --pilot-evidence /var/lib/agent-continuity/pilot-evidence.json --source-sha <sha>`.
- Produces: atomic config update only when every gate passes; otherwise exits non-zero without mutation.

- [ ] **Step 1: Write failing enablement-gate tests**

```python
def test_refuses_without_green_pilot_evidence(self):
    rc = enable(self.cfg, pilot=None, source_sha=SHA)
    self.assertEqual(2, rc)
    self.assertFalse(json.loads(self.cfg.read_text())["auto_dispatch"])

def test_refuses_forbidden_enabled_agents(self):
    self.write_config(agents=[{"name":"codex","enabled":True}])
    self.assertEqual(2, enable(self.cfg, self.pilot(), SHA))
```

- [ ] **Step 2: Run gate tests and verify RED**

Run: `python3 -m unittest tests.continuity.test_enable_auto_dispatch -v`
Expected: FAIL because the gate script is absent.

- [ ] **Step 3: Implement full preflight and atomic mutation**

Validate: exact runtime/source SHA, pilot status=`green` and matching SHA/repository, worker/publisher units installed, path units enabled, Gemini env exists with mode `0640` or stricter and correct group, publisher credential directory mode `0700` or stricter, Gemini CLI executable, policy readable, only `gemini/rooter` agent names present, `rooter.enabled=false`, and no forbidden automatic agent enabled anywhere.

On success rewrite only the operator config fields needed for automation: `auto_dispatch=true`, `gemini.enabled=true`, `rooter.enabled=false`, queue/result roots and worker policy settings. Preserve repository/operator settings. Write temp file beside config, fsync, mode/owner, then `os.replace`.

- [ ] **Step 4: Keep repository templates fail-closed**

`deploy/continuity/config.production.json` and `tools/continuity/config.example.json` remain `auto_dispatch=false`. They document queue roots and only `gemini/rooter`; no deploy ever flips production automatically.

- [ ] **Step 5: Verify gate tests GREEN**

Run: `python3 -m unittest tests.continuity.test_enable_auto_dispatch tests.continuity.test_systemd_contract -v`
Expected: PASS.
- [ ] **Step 6: Commit**

```bash
git add scripts/enable-continuity-auto-dispatch.py tests/continuity/test_enable_auto_dispatch.py deploy/continuity/config.production.json tools/continuity/config.example.json
git commit -m "feat(continuity): gate recurring automation enablement"
```

---

### Task 8: Disposable interruption/recovery pilot

**Files:**
- Create: `scripts/run-continuity-pilot.sh`
- Create: `tests/continuity/test_pilot_contract.py`
- Modify: `tools/continuity/cli.py`

**Interfaces:**
- Pilot repository: the same allowed GitHub repository, but a dedicated branch `agent/PILOT-<timestamp>-continuity-recovery` and test-only file under `tests/continuity/pilot-fixtures/`.
- Produces: `/var/lib/agent-continuity/pilot-evidence.json` with source/runtime SHA, task/branch, first/second session IDs, interruption evidence, CI/PR/merge evidence, secret-boundary assertions and final `status`.

- [ ] **Step 1: Write failing pilot contract test**

```python
def test_pilot_script_requires_interrupt_resume_merge_and_secret_gates(self):
    text = Path("scripts/run-continuity-pilot.sh").read_text()
    for marker in ("AGENT_LOST", "NEEDS_RESUME", "GEMINI", "PR", "CI", "MERGED", "secret_boundary"):
        self.assertIn(marker, text)
```

- [ ] **Step 2: Run pilot contract test and verify RED**

Run: `python3 -m unittest tests.continuity.test_pilot_contract -v`
Expected: FAIL because pilot script is absent.

- [ ] **Step 3: Implement pilot preflight**

Before creating anything, verify `auto_dispatch=false`, worker/publisher credential separation, worker CLI smoke, exact deployed runtime SHA, clean source mirror, CI workflow available, repository deploy key write access, and the GitHub Actions publisher workflow permission contract. If the repo-scoped deploy key or workflow permissions cannot be proven, exit with `BLOCKED_EXTERNAL` before any AI job is launched.
- [ ] **Step 4: Create isolated pilot task and first lost lease**

Create worker-owned source/worktree, persist task/base/head, claim a short session A lease, add only a deterministic pilot fixture marker, then let/force the lease expiry through the normal ledger expiry API. Reconcile and assert persisted transition `AGENT_LOST -> NEEDS_RESUME`; do not reset/clean the fixture.

- [ ] **Step 5: Resume the same task through the real Gemini worker**

Claim session B, render the redacted resume packet, enqueue a real Gemini job that instructs the worker to complete only the deterministic pilot fixture, and validate that Gemini produces a local commit with no unrelated paths. Assert worker environment lacks Codex/Claude/OpenAI/publisher credentials.

- [ ] **Step 6: Publish, CI, merge and reconcile**

The host publisher pushes only the exact branch head through the repo-scoped deploy key. The `continuity-publisher.yml` workflow creates/reuses the PR and enables the configured auto-merge path with GitHub's ephemeral repository token; the controller/pilot polls the public PR/check state with bounded retries, records the merged SHA, reconciles that exact SHA, and verifies the pilot fixture in `origin/main`.

- [ ] **Step 7: Emit signed-by-state pilot evidence and clean pilot artifacts**

Evidence JSON includes digests and booleans only, never credentials/prompt content. `status="green"` requires every gate; otherwise `status="failed"` with bounded reason. Remove the local disposable pilot worktree after merge only when clean and fully reconciled; preserve receipts/ledger evidence.

- [ ] **Step 8: Verify pilot contract tests GREEN**

Run: `python3 -m unittest tests.continuity.test_pilot_contract tests.continuity.test_ledger tests.continuity.test_worker tests.continuity.test_publisher -v`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add scripts/run-continuity-pilot.sh tests/continuity/test_pilot_contract.py tools/continuity/cli.py
git commit -m "test(continuity): add interruption recovery pilot"
```

---

### Task 9: Runbook migration, full verification, PR and production rollout

**Files:**
- Modify: `docs/runbooks/agent-continuity-controller.md`
- Modify: `scripts/agent-continuity-validate.sh`
- Modify: `.github/workflows/ci.yml` only if final coverage commands are missing.

**Interfaces:**
- Documents and validates the production state transition; no new runtime API.

- [ ] **Step 1: Write failing documentation/contract assertion**

Add a validation assertion that the runbook contains `automatic recurring: Gemini -> certified rooter only` and does not contain the obsolete automatic order `Codex, ChatGPT, Claude, then Gemini`.
- [ ] **Step 2: Run documentation contract and verify RED**

Run: `bash scripts/agent-continuity-validate.sh`
Expected: FAIL because the runbook still contains the obsolete recurring-agent order.

- [ ] **Step 3: Migrate the runbook to the split trust model**

Document controller read-only responsibilities, worker/publisher identities, job/result roots, credential separation, automatic recurring order `Gemini -> certified rooter only`, manual interactive-agent separation, fail-closed enablement command, pilot evidence requirements, rollback, and token-attribution checks. Remove any wording that implies Codex/ChatGPT/Claude are automatic fallbacks.

- [ ] **Step 4: Run the complete local verification stack**

Run:
```bash
python3 -m unittest discover -s tests/continuity -p 'test_*.py' -v
python3 -m py_compile tools/continuity/*.py scripts/*.py
bash -n scripts/install-continuity-controller.sh scripts/run-continuity-pilot.sh scripts/auto-deploy.sh
bash scripts/agent-continuity-validate.sh
git diff --check
```
Expected: all commands exit `0`.
- [ ] **Step 2: Run contract and verify RED**

Run: `bash scripts/agent-continuity-validate.sh`
Expected: FAIL until the obsolete runbook policy is removed.

- [ ] **Step 3: Update runbook and operational commands**

Document controller/worker/publisher trust domains, automatic policy `Gemini -> certified rooter only`, service paths, credential separation, pilot procedure, enablement gate, token-attribution queries, rollback, and the rule that legacy worktrees remain manual.

- [ ] **Step 4: Run complete continuity verification**

Run:

```bash
python3 -m unittest discover -s tests/continuity -p 'test_*.py' -v
python3 -m py_compile tools/continuity/*.py scripts/enable-continuity-auto-dispatch.py
bash -n scripts/install-continuity-controller.sh
bash -n scripts/run-continuity-pilot.sh
bash scripts/agent-continuity-validate.sh
git diff --check
```

Expected: all tests/commands exit 0 with no warnings containing secrets.

- [ ] **Step 5: Run repository-wide required CI-equivalent checks**

Run the same PHP, Node, TOTP, continuity, syntax and security commands from `.github/workflows/ci.yml`. Expected: zero failures.

- [ ] **Step 6: Commit documentation/validation migration**

```bash
git add docs/runbooks/agent-continuity-controller.md scripts/agent-continuity-validate.sh .github/workflows/ci.yml
git commit -m "docs(continuity): document isolated recurring worker rollout"
```
- [ ] **Step 5: Commit the runbook/validation migration**

```bash
git add docs/runbooks/agent-continuity-controller.md scripts/agent-continuity-validate.sh .github/workflows/ci.yml
git commit -m "docs(continuity): document isolated recurring Gemini worker"
```

- [ ] **Step 6: Push implementation branch and open PR**

Push only after the complete verification stack is green. Open a PR that links the approved spec and this plan, lists every security boundary, records the local test count, and states that production remains `auto_dispatch=false` until the real pilot is green.

- [ ] **Step 7: Require GitHub CI success before merge**

Run `gh pr checks <PR> --watch --interval 10`. Do not merge with pending, skipped-required, cancelled, or failed checks. After all required checks pass, merge using the repository's normal merge strategy and record the merge SHA.

- [ ] **Step 8: Deploy the exact merged SHA through the existing root auto-deploy path**

From the approved administrative SSH route, run `sudo systemctl start amazon-returns-deploy.service`. Verify `/opt/agent-continuity/.source-sha` equals the merge SHA and confirm the controller timer remains active with `auto_dispatch=false`.
- [ ] **Step 9: Execute the disposable real pilot with automation still disabled**

Run the pilot manually as root/operator. Require `pilot-evidence.json.status == "green"`, exact merge/runtime SHA match, preserved interruption evidence, real Gemini worker receipt, successful CI/merge, and positive secret-boundary assertions. Any failure leaves `auto_dispatch=false` and preserves forensic artifacts.

- [ ] **Step 10: Enable recurring Gemini through the fail-closed gate**

Only after Step 9 is green, run `enable-continuity-auto-dispatch.py` with the exact runtime SHA and pilot evidence. Re-read the installed config without printing credentials and assert: `auto_dispatch=true`, `gemini.enabled=true`, `rooter.enabled=false`, and no Codex/ChatGPT/Claude automatic candidates.

- [ ] **Step 11: Observe scheduled production cycles and audit token provenance**

Observe at least three 30-minute controller cycles. For every provider invocation, verify task ID, lease/session ID, origin=`recurring`, provider=`gemini`, configured recurring model, start/end/exit telemetry, and corresponding job/result digest. Assert there are zero unattributed invocations and zero automatic Codex/ChatGPT/Claude processes on all four hosts.

- [ ] **Step 12: Record acceptance evidence**

Record implementation branch/base, implementation PR, merge SHA, deployed runtime SHA, pilot task/PR/merge SHA, test/CI results, service/path-unit state, recurring invocation counts by provider, blocked legacy worktree count, and rollback command set. Production acceptance requires all spec criteria simultaneously; otherwise revert configuration to `auto_dispatch=false`.

---

## Execution order and stop conditions

Execute Tasks 1-9 in order. Each task uses RED -> minimal implementation -> GREEN -> focused commit. Do not combine unrelated tasks into one commit. A failing security boundary, unexpected secret exposure, ambiguous worktree ownership, unavailable narrow publication credential, or non-Gemini automatic selection is a hard fail-closed condition: keep `auto_dispatch=false`, preserve evidence, and fix the gate before continuing.
- [ ] **Step 7: Push implementation branch and open PR**

Push the implementation branch, create one PR referencing the approved spec/plan, and include exact verification evidence. Do not merge with pending/failed checks.

- [ ] **Step 8: Merge only after required checks are green**

Record PR number, implementation head SHA and merge SHA. Verify `main` contains the exact reviewed head and no unrelated commits were introduced by the implementation branch.

- [ ] **Step 9: Deploy the exact merge SHA with `auto_dispatch=false`**

Use the existing root-owned auto-deploy/install path. Verify `/opt/agent-continuity/.source-sha` equals the merge SHA, controller remains read-only, worker/publisher identities/units are installed, credential files retain safe ownership/modes, and worker/publisher `.path` units contain no pending jobs.

- [ ] **Step 10: Run the real disposable pilot against the deployed SHA**

Run `sudo scripts/run-continuity-pilot.sh --source-sha <merge-sha>` through the authorized root path. Inspect the resulting evidence JSON without printing credentials. If any gate fails, keep `auto_dispatch=false`, preserve evidence and return to the failing task.

- [ ] **Step 11: Enable automatic dispatch only from green pilot evidence**

Run the idempotent enablement script with exact SHA/evidence paths, then verify config contains `auto_dispatch=true`, `gemini.enabled=true`, `rooter.enabled=false`, and no Codex/ChatGPT/Claude entries are enabled or eligible.

- [ ] **Step 12: Validate recurring production behavior**

Trigger two explicit controller reconciliation passes and observe at least one subsequent timer-triggered pass. For every pass, query receipts/ledger/journal and prove: no unexplained AI call, every recurring invocation has task+lease+provider+model attribution, no forbidden provider launched, and no secret appeared in logs.

- [ ] **Step 13: Final acceptance record**

Record initial SHA, implementation head, merge/deployed SHA, pilot task/PR/merged SHA, test totals, worker/publisher unit states, config agent policy, last recurring invocation attribution and any blocked tasks. Completion requires all spec acceptance criteria simultaneously true.

---

## Execution Order and Review Gates

Tasks 1-7 are code/security foundations and each gets its own RED→GREEN→commit cycle. Task 8 is the pilot mechanism, not the real production pilot. Task 9 performs full verification, PR review, exact-SHA deployment, the real pilot and only then production enablement.

If any security boundary test fails, stop that task and fix root cause before proceeding. If the real pilot fails, `auto_dispatch` remains false; do not bypass the pilot marker or manually edit it to green.
