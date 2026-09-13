# Global Agent Continuity Controller Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a persistent multi-repository continuity controller that detects abandoned work, preserves it safely, generates deterministic resume packets, and ensures tasks reach `MERGED + VERIFIED` or an explicit external blocker.

**Architecture:** Implement the controller as a Python 3 standard-library service under `tools/continuity/`, using SQLite for the durable task ledger and `git`/`gh` subprocess adapters for local and GitHub state. Keep repository scanning read-only by default, isolate new work by task worktree, separate lifecycle `status` from operational `classification`, and only enable automatic agent dispatch after scanner, lease, secret-redaction, and recovery tests are green.

**Tech Stack:** Python 3 stdlib (`sqlite3`, `dataclasses`, `subprocess`, `json`, `pathlib`, `datetime`, `argparse`), Git CLI, GitHub CLI, systemd, existing GitHub Actions CI.

**Spec:** `docs/superpowers/specs/2026-09-13-global-agent-continuity-controller-design.md`

## Global Constraints

- Never execute `git reset --hard`, `git clean`, forced checkout, force-push, or destructive worktree cleanup automatically.
- Preserve unrelated/uncommitted work and abort/reclassify on concurrent changes to the same task/worktree.
- One active lease per `TASK_ID`; claims and renewals are transactional.
- `status` and `classification` are distinct persisted fields.
- No secrets, cookies, passwords, tokens, private keys, TOTP seeds, or sensitive payloads may be stored in ledger, logs, resume packets, commits, or PRs.
- Initial rollout is read-only auditing; automatic resume is enabled only after isolation, lease, redaction, and recovery tests pass.
- Reconciliation cadence is 30 minutes plus explicit lifecycle events; no 5-minute polling.
- A task is complete only after integration to the target branch and project-specific post-merge verification.
- Read and obey `AGENTS.md`, `AGENTS-VM-ACCESS.md`, `AI-TO-CLI-PROTOCOL.md`, `docs/REGRAS-DE-ENTREGA.md`, and `docs/MEMORIA-DO-PROJETO.md` before implementation or VM changes.

---

## File Structure

Create the controller as focused modules:

- `tools/continuity/__init__.py` — package marker and version constant.
- `tools/continuity/model.py` — enums/dataclasses for task state, classifications, findings, and resume packets.
- `tools/continuity/ledger.py` — SQLite schema, transactions, task CRUD, leases, checkpoints, queue ordering.
- `tools/continuity/git_scan.py` — read-only Git/worktree scanner and Git operation detection.
- `tools/continuity/classifier.py` — deterministic classification from local/remote evidence.
- `tools/continuity/github_state.py` — GitHub CLI adapter for PR/check/branch reconciliation.
- `tools/continuity/resume_packet.py` — redaction and deterministic resume packet rendering.
- `tools/continuity/worktrees.py` — creation/validation of isolated task worktrees for new tasks only.
- `tools/continuity/dispatcher.py` — claim + agent availability/fallback orchestration; automatic launching guarded by configuration.
- `tools/continuity/controller.py` — reconciliation loop and lifecycle orchestration.
- `tools/continuity/cli.py` — operator CLI (`init`, `scan`, `queue`, `show`, `claim`, `heartbeat`, `resume-packet`, `reconcile`).
- `tools/continuity/config.example.json` — non-secret multi-host/repository configuration example.
- `scripts/install-continuity-controller.sh` — conservative systemd installer.
- `deploy/systemd/agent-continuity-controller.service` — one-shot reconciliation service.
- `deploy/systemd/agent-continuity-controller.timer` — persistent 30-minute timer.
- `tests/continuity/` — Python `unittest` suite and temporary Git repository integration fixtures.
- `.github/workflows/ci.yml` — execute continuity tests and syntax checks.
- `docs/runbooks/agent-continuity-controller.md` — operation, rollout, recovery, and initial audit procedure.

---

### Task 1: Durable task model and SQLite ledger

**Files:**
- Create: `tools/continuity/__init__.py`
- Create: `tools/continuity/model.py`
- Create: `tools/continuity/ledger.py`
- Create: `tests/continuity/__init__.py`
- Create: `tests/continuity/test_ledger.py`

**Interfaces:**
- Produces: `TaskStatus`, `Classification`, `TaskRecord`, `LeaseConflict`, `Ledger`.
- `Ledger(path: Path)` creates/opens the database and applies schema idempotently.
- `Ledger.create_task(task: TaskRecord) -> None`
- `Ledger.get_task(task_id: str) -> TaskRecord | None`
- `Ledger.claim(task_id: str, agent_type: str, session_id: str, now: datetime, lease_seconds: int) -> TaskRecord`
- `Ledger.heartbeat(task_id: str, session_id: str, now: datetime, lease_seconds: int) -> TaskRecord`
- `Ledger.expire_leases(now: datetime) -> list[TaskRecord]`
- `Ledger.list_resume_queue() -> list[TaskRecord]`

- [ ] **Step 1: Write failing ledger/state tests**

Create `tests/continuity/test_ledger.py` with tests covering status/classification separation, transactional claims, heartbeat renewal, lease expiry, and queue ordering. Core assertions:

```python
from datetime import datetime, timedelta, timezone
from pathlib import Path
import tempfile
import unittest

from tools.continuity.ledger import Ledger, LeaseConflict
from tools.continuity.model import Classification, TaskRecord, TaskStatus

UTC = timezone.utc

class LedgerTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.ledger = Ledger(Path(self.tmp.name) / "ledger.sqlite3")

    def tearDown(self):
        self.tmp.cleanup()

    def task(self, task_id="TASK-20260913-001"):
        now = datetime(2026, 9, 13, 21, 0, tzinfo=UTC)
        return TaskRecord(
            task_id=task_id,
            repository="Vivaliz-site/amazon-returns-safet",
            host="shopvivaliz-free-a1",
            worktree_path="/srv/continuity/worktrees/amazon-returns-safet/" + task_id,
            branch="agent/" + task_id + "-continuity",
            base_sha="a" * 40,
            current_head="a" * 40,
            objective="test continuity",
            status=TaskStatus.QUEUED,
            classification=Classification.NEEDS_RESUME,
            created_at=now,
            updated_at=now,
        )

    def test_status_and_classification_are_independent(self):
        task = self.task()
        task.status = TaskStatus.MERGED
        task.classification = Classification.MERGED_UNVERIFIED
        self.ledger.create_task(task)
        loaded = self.ledger.get_task(task.task_id)
        self.assertEqual(TaskStatus.MERGED, loaded.status)
        self.assertEqual(Classification.MERGED_UNVERIFIED, loaded.classification)

    def test_second_claim_is_rejected_while_lease_is_active(self):
        task = self.task()
        self.ledger.create_task(task)
        now = task.created_at
        self.ledger.claim(task.task_id, "codex", "session-a", now, 1800)
        with self.assertRaises(LeaseConflict):
            self.ledger.claim(task.task_id, "chatgpt", "session-b", now + timedelta(seconds=1), 1800)

    def test_expired_lease_becomes_needs_resume(self):
        task = self.task()
        self.ledger.create_task(task)
        now = task.created_at
        self.ledger.claim(task.task_id, "codex", "session-a", now, 60)
        expired = self.ledger.expire_leases(now + timedelta(seconds=61))
        self.assertEqual([task.task_id], [t.task_id for t in expired])
        loaded = self.ledger.get_task(task.task_id)
        self.assertEqual(TaskStatus.NEEDS_RESUME, loaded.status)
        self.assertEqual(Classification.NEEDS_RESUME, loaded.classification)
```

- [ ] **Step 2: Run tests and verify they fail before implementation**

Run:

```bash
python3 -m unittest tests.continuity.test_ledger -v
```

Expected: import/module failures because `tools.continuity.ledger` and `model` do not exist.

- [ ] **Step 3: Implement immutable enums/dataclass contract**

In `model.py`, define string enums exactly matching the approved spec and a `TaskRecord` dataclass whose optional fields default to `None`/empty values. Persist timestamps as ISO-8601 UTC strings and convert on load.

- [ ] **Step 4: Implement SQLite schema and transactional lease operations**

Use `BEGIN IMMEDIATE` for claim/heartbeat transitions. Schema must include all approved fields plus a monotonically increasing `revision INTEGER NOT NULL DEFAULT 0`; every mutating update increments `revision` for optimistic concurrency evidence. Enable `PRAGMA journal_mode=WAL` and `PRAGMA foreign_keys=ON`.

- [ ] **Step 5: Run focal tests**

```bash
python3 -m unittest tests.continuity.test_ledger -v
```

Expected: all ledger tests PASS.

- [ ] **Step 6: Commit**

```bash
git add tools/continuity/__init__.py tools/continuity/model.py tools/continuity/ledger.py tests/continuity/__init__.py tests/continuity/test_ledger.py
git commit -m "feat: add continuity task ledger"
```

---

### Task 2: Read-only Git repository/worktree scanner

**Files:**
- Create: `tools/continuity/git_scan.py`
- Create: `tests/continuity/git_fixture.py`
- Create: `tests/continuity/test_git_scan.py`

**Interfaces:**
- Consumes: `TaskRecord` types from Task 1.
- Produces: `GitFinding` dataclass and `scan_repository(repo: Path, base_ref: str = "origin/main") -> GitFinding`.
- `GitFinding` exposes `branch`, `head`, `upstream`, `modified`, `staged`, `untracked`, `stash_count`, `ahead_count`, `behind_count`, `detached`, `operation_in_progress`, `conflicted`, `worktrees`, and `lock_files`.

- [ ] **Step 1: Build isolated temporary Git fixture helpers**

`tests/continuity/git_fixture.py` must create bare remote + clone using only temp directories, configure a test identity, create `main`, push it, and provide methods for dirty, staged, untracked, local-ahead, detached, merge-conflict, and rebase-state scenarios.

- [ ] **Step 2: Write failing scanner tests**

Representative assertions in `test_git_scan.py`:

```python
class GitScanTest(unittest.TestCase):
    def test_detects_dirty_staged_untracked_and_local_ahead(self):
        fx = GitFixture()
        fx.write("tracked.txt", "changed\n")
        fx.write("staged.txt", "new\n")
        fx.git("add", "staged.txt")
        fx.write("untracked.txt", "new\n")
        finding = scan_repository(fx.repo)
        self.assertIn("tracked.txt", finding.modified)
        self.assertIn("staged.txt", finding.staged)
        self.assertIn("untracked.txt", finding.untracked)

    def test_detects_detached_head(self):
        fx = GitFixture()
        fx.git("checkout", "--detach", "HEAD")
        self.assertTrue(scan_repository(fx.repo).detached)
```

Also test merge/rebase/cherry-pick/revert/bisect markers by creating the real Git states where practical, and marker directories/files only when Git itself uses those exact paths.

- [ ] **Step 3: Verify failures**

```bash
python3 -m unittest tests.continuity.test_git_scan -v
```

Expected: scanner import/behavior failures.

- [ ] **Step 4: Implement scanner using read-only commands**

Allowed commands include `git status --porcelain=v2 --branch -z`, `git rev-parse`, `git rev-list --left-right --count`, `git stash list`, and `git worktree list --porcelain`. The module must not expose any destructive helper.

Detect operation markers via `git rev-parse --git-path <marker>` for `MERGE_HEAD`, `rebase-merge`, `rebase-apply`, `CHERRY_PICK_HEAD`, `REVERT_HEAD`, and `BISECT_LOG`.

- [ ] **Step 5: Run tests**

```bash
python3 -m unittest tests.continuity.test_git_scan -v
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tools/continuity/git_scan.py tests/continuity/git_fixture.py tests/continuity/test_git_scan.py
git commit -m "feat: scan abandoned git work safely"
```

---

### Task 3: Deterministic classifier and resume queue evidence

**Files:**
- Create: `tools/continuity/classifier.py`
- Create: `tests/continuity/test_classifier.py`
- Modify: `tools/continuity/ledger.py`

**Interfaces:**
- Consumes: `GitFinding`, `TaskRecord`.
- Produces: `classify(task: TaskRecord | None, finding: GitFinding, remote: RemoteState | None) -> ClassificationDecision`.
- `ClassificationDecision(classification, status, reason, priority, next_action)`.

- [ ] **Step 1: Write failing table-driven tests**

Cover exact precedence:

1. active unexpired lease -> `ACTIVE`;
2. interrupted Git operation -> `NEEDS_RESUME`, priority 10;
3. local dirty/untracked work without task -> `ORPHAN_UNKNOWN`, priority 20;
4. local commits ahead but not pushed -> `NEEDS_RESUME`, priority 30;
5. pushed branch without PR -> `READY_FOR_PR`, priority 40;
6. PR with failed check/conflict -> `PR_BLOCKED`, priority 50;
7. merged but verification missing -> `MERGED_UNVERIFIED`, priority 60;
8. merged + verified -> `DONE`, priority 1000;
9. explicit external blocker -> `BLOCKED_EXTERNAL`.

Use assertions on both classification and `next_action` so the output remains actionable.

- [ ] **Step 2: Run failing tests**

```bash
python3 -m unittest tests.continuity.test_classifier -v
```

- [ ] **Step 3: Implement pure classification function**

No network or subprocess access is allowed in `classifier.py`; classification must depend only on input evidence, making it idempotent and testable.

- [ ] **Step 4: Persist priority and next action in ledger queue queries**

`Ledger.list_resume_queue()` sorts by numeric risk priority, then oldest `updated_at`, then `task_id` for deterministic ordering.

- [ ] **Step 5: Run focal tests**

```bash
python3 -m unittest tests.continuity.test_classifier tests.continuity.test_ledger -v
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add tools/continuity/classifier.py tools/continuity/ledger.py tests/continuity/test_classifier.py
git commit -m "feat: classify continuity findings deterministically"
```

---

### Task 4: GitHub reconciliation adapter

**Files:**
- Create: `tools/continuity/github_state.py`
- Create: `tests/continuity/test_github_state.py`

**Interfaces:**
- Produces: `RemoteState` dataclass and `GitHubStateReader`.
- `GitHubStateReader.branch_state(repository: str, branch: str, base: str) -> RemoteState`
- The adapter shells out to `gh api`/`gh pr list` with JSON output and accepts an injectable command runner for tests.

- [ ] **Step 1: Write fake-runner tests**

Test JSON fixtures for: branch present/no PR, open green PR, open failed PR, merged PR, closed-unmerged PR, and unavailable GitHub credentials. Assert unavailable auth becomes an evidence error/blocker, never `DONE`.

- [ ] **Step 2: Run and verify failure**

```bash
python3 -m unittest tests.continuity.test_github_state -v
```

- [ ] **Step 3: Implement minimal GitHub CLI adapter**

Use structured JSON fields only; never scrape terminal text. Do not place tokens in arguments or logs. Capture stderr, redact credential-shaped values, and return typed `RemoteState` evidence.

- [ ] **Step 4: Run tests**

```bash
python3 -m unittest tests.continuity.test_github_state -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tools/continuity/github_state.py tests/continuity/test_github_state.py
git commit -m "feat: reconcile continuity state with github"
```

---

### Task 5: Safe resume packets and redaction

**Files:**
- Create: `tools/continuity/resume_packet.py`
- Create: `tests/continuity/test_resume_packet.py`

**Interfaces:**
- `redact(text: str) -> str`
- `render_resume_packet(task: TaskRecord, finding: GitFinding, remote: RemoteState | None) -> str`

- [ ] **Step 1: Write failing deterministic/redaction tests**

Include representative fake values matching common secret forms without using real credentials: GitHub tokens, OpenAI-style keys, bearer headers, `otpauth://`, PEM markers, cookies, password assignments. Assert none survives rendering.

Also render twice from identical evidence and assert byte-for-byte equality.

- [ ] **Step 2: Run and verify failure**

```bash
python3 -m unittest tests.continuity.test_resume_packet -v
```

- [ ] **Step 3: Implement packet renderer**

Render every field required by the approved spec and finish with fixed safety instructions:

```text
Continue exatamente desta worktree e branch.
Nao recrie a implementacao do zero.
Nao descarte nem sobrescreva alteracoes existentes.
Valide antes de commit/push.
Conclua commit -> push -> PR -> checks -> merge -> verificacao.
Registre checkpoint seguro antes de encerrar.
```

Never include full arbitrary file contents or message bodies; include file paths and bounded diff summaries only.

- [ ] **Step 4: Run tests**

```bash
python3 -m unittest tests.continuity.test_resume_packet -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tools/continuity/resume_packet.py tests/continuity/test_resume_packet.py
git commit -m "feat: generate safe continuity resume packets"
```

---

### Task 6: Isolated worktrees, controller loop, and operator CLI

**Files:**
- Create: `tools/continuity/worktrees.py`
- Create: `tools/continuity/controller.py`
- Create: `tools/continuity/cli.py`
- Create: `tools/continuity/config.example.json`
- Create: `tests/continuity/test_worktrees.py`
- Create: `tests/continuity/test_controller.py`
- Create: `tests/continuity/test_cli.py`

**Interfaces:**
- `create_task_worktree(repo: Path, root: Path, task_id: str, slug: str, base_ref: str) -> WorktreeResult`
- `Controller.reconcile_repository(repo_config: RepoConfig) -> ReconcileReport`
- CLI commands: `init`, `scan`, `queue`, `show TASK_ID`, `claim TASK_ID`, `heartbeat TASK_ID`, `resume-packet TASK_ID`, `reconcile`.

- [ ] **Step 1: Write worktree safety tests**

Assert the path convention `<root>/worktrees/<repo>/<TASK_ID>`, branch convention `agent/<TASK_ID>-<slug>`, refusal when target path exists with unknown content, and refusal to reuse deploy checkouts.

- [ ] **Step 2: Write controller idempotency tests**

Run reconciliation twice over identical local/remote evidence. Assert no duplicate task is created, revision changes only when evidence/state changes, and `ORPHAN_UNKNOWN` findings remain read-only until explicitly associated.

- [ ] **Step 3: Write CLI smoke tests**

Use a temporary ledger/config and invoke `cli.main([...])`; assert `scan --json` produces parseable JSON and `queue --json` is deterministic.

- [ ] **Step 4: Run failing tests**

```bash
python3 -m unittest tests.continuity.test_worktrees tests.continuity.test_controller tests.continuity.test_cli -v
```

- [ ] **Step 5: Implement isolated worktree creation and controller reconciliation**

Worktree creation is permitted only for new tasks; the initial audit never migrates/moves existing dirty checkouts. Controller writes ledger findings but does not auto-commit or mutate existing Git work in audit mode.

- [ ] **Step 6: Implement CLI**

Default configuration path: `${CONTINUITY_CONFIG:-/etc/agent-continuity/config.json}`. Default ledger path from config. `--json` outputs structured evidence suitable for agents and monitoring.

- [ ] **Step 7: Run tests**

```bash
python3 -m unittest tests.continuity.test_worktrees tests.continuity.test_controller tests.continuity.test_cli -v
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add tools/continuity/worktrees.py tools/continuity/controller.py tools/continuity/cli.py tools/continuity/config.example.json tests/continuity/test_worktrees.py tests/continuity/test_controller.py tests/continuity/test_cli.py
git commit -m "feat: add continuity controller and operator cli"
```

---

### Task 7: Dispatcher guardrails and fallback sequencing

**Files:**
- Create: `tools/continuity/dispatcher.py`
- Create: `tests/continuity/test_dispatcher.py`

**Interfaces:**
- `AgentCandidate(name: str, command: list[str], enabled: bool)`
- `Dispatcher.claim_next(now: datetime) -> DispatchDecision | None`
- `Dispatcher.select_agent(candidates: list[AgentCandidate], availability: Mapping[str, bool]) -> AgentCandidate | None`
- Preferred order: `codex`, `chatgpt`, `claude`, `gemini`.

- [ ] **Step 1: Write fallback and concurrency tests**

Assert Codex wins when available, ChatGPT is next, then Claude, then Gemini. Assert an active lease prevents a second dispatcher from claiming the same task. Assert failure to launch releases/requeues only after persisting attempt evidence and never changes worktree/branch.

- [ ] **Step 2: Run tests and verify failure**

```bash
python3 -m unittest tests.continuity.test_dispatcher -v
```

- [ ] **Step 3: Implement guarded dispatcher**

Automatic process launch must require `auto_dispatch=true` in config. With the default `false`, dispatcher only produces the selected Resume Packet and command metadata without launching anything. Do not embed credentials or login arguments.

- [ ] **Step 4: Run tests**

```bash
python3 -m unittest tests.continuity.test_dispatcher tests.continuity.test_ledger -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tools/continuity/dispatcher.py tests/continuity/test_dispatcher.py
git commit -m "feat: add guarded multi-agent resume dispatcher"
```

---

### Task 8: systemd service/timer, installer, and CI integration

**Files:**
- Create: `deploy/systemd/agent-continuity-controller.service`
- Create: `deploy/systemd/agent-continuity-controller.timer`
- Create: `scripts/install-continuity-controller.sh`
- Create: `tests/continuity/test_systemd_contract.py`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Service executes one reconciliation pass and exits.
- Timer runs every 30 minutes and is persistent.

- [ ] **Step 1: Write systemd contract tests**

Assertions:

```python
service = Path("deploy/systemd/agent-continuity-controller.service").read_text()
timer = Path("deploy/systemd/agent-continuity-controller.timer").read_text()
self.assertIn("python3 -m tools.continuity.cli reconcile", service)
self.assertIn("OnUnitActiveSec=1800", timer)
self.assertNotIn("OnUnitActiveSec=300", timer)
self.assertIn("Persistent=true", timer)
```

- [ ] **Step 2: Run test and verify failure**

```bash
python3 -m unittest tests.continuity.test_systemd_contract -v
```

- [ ] **Step 3: Create service/timer and conservative installer**

Service must run as a dedicated non-root user when deployed, use explicit working/config paths, and set `NoNewPrivileges=true`, `PrivateTmp=true`, `ProtectSystem=strict`, with only controller state/worktree roots writable. Installer must refuse missing config and must not overwrite an existing config containing credentials.

- [ ] **Step 4: Add CI coverage**

Add to `.github/workflows/ci.yml`:

```yaml
      - name: Continuity controller tests
        run: |
          set -e
          python3 -m unittest discover -s tests/continuity -p 'test_*.py' -v
          python3 -m py_compile tools/continuity/*.py
          bash -n scripts/install-continuity-controller.sh
```

- [ ] **Step 5: Run focal CI-equivalent commands**

```bash
python3 -m unittest discover -s tests/continuity -p 'test_*.py' -v
python3 -m py_compile tools/continuity/*.py
bash -n scripts/install-continuity-controller.sh
git diff --check
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add deploy/systemd/agent-continuity-controller.service deploy/systemd/agent-continuity-controller.timer scripts/install-continuity-controller.sh tests/continuity/test_systemd_contract.py .github/workflows/ci.yml
git commit -m "feat: run continuity reconciliation every 30 minutes"
```

---

### Task 9: Initial audit and operational runbook

**Files:**
- Create: `docs/runbooks/agent-continuity-controller.md`
- Create: `tests/continuity/test_initial_audit.py`

**Interfaces:**
- CLI `reconcile --audit-only --json` returns a report with repository, host, path, branch, HEAD, changed files, exclusive commits, upstream, PR, CI, age/last activity when available, classification, risk priority, and next action.

- [ ] **Step 1: Write audit-only non-mutation test**

Create a temp repository with dirty/untracked/ahead work, snapshot `.git` refs and working files, run `reconcile --audit-only`, and assert every ref/file hash is unchanged while findings are persisted to a temporary ledger.

- [ ] **Step 2: Run and verify failure**

```bash
python3 -m unittest tests.continuity.test_initial_audit -v
```

- [ ] **Step 3: Complete audit-only behavior and runbook**

Runbook must document phases A-E, exact safe commands, `ORPHAN_UNKNOWN` triage, how to associate an existing finding with a task without moving files, how to inspect Resume Packets, and how to disable/rollback the timer without deleting ledger/worktrees.

- [ ] **Step 4: Run tests**

```bash
python3 -m unittest tests.continuity.test_initial_audit -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add docs/runbooks/agent-continuity-controller.md tests/continuity/test_initial_audit.py
git commit -m "docs: add continuity controller audit runbook"
```

---

### Task 10: Full validation, PR, merge, deploy, pilot interruption test

**Files:**
- No new source files expected unless validation exposes a defect.
- Update the same task branch with fixes discovered by validation.

- [ ] **Step 1: Run full repository validation**

```bash
set -e
python3 -m unittest discover -s tests/continuity -p 'test_*.py' -v
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
node --test tests/*.test.mjs
find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l
node --check scripts/amazon-returns/safe-t-status-parser.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
bash -n scripts/auto-deploy.sh scripts/provision-production.sh scripts/verify-live-tenant-foundation.sh scripts/install-continuity-controller.sh
git diff --check
```

Fix any regression and repeat until green.

- [ ] **Step 2: Perform security/diff review**

```bash
git status --porcelain=v1 -uall
git diff --check
git grep -n -E 'BEGIN (OPENSSH|RSA|EC|PRIVATE) KEY|otpauth://|Authorization: Bearer|sk-[A-Za-z0-9_-]{16,}' -- tools/continuity tests/continuity deploy/systemd scripts docs || true
```

Any real secret-like hit must be removed or replaced with a safe synthetic fixture before push.

- [ ] **Step 3: Push branch and open PR**

Use the validated branch head. PR body must reference the spec and plan, state that initial rollout defaults to audit-only/`auto_dispatch=false`, and list validation evidence.

- [ ] **Step 4: Follow CI to green and remediate in the same branch**

Do not merge an older head after later fixes. Re-run local focal/full tests after each CI fix.

- [ ] **Step 5: Merge validated head**

Confirm target `main` contains the intended merge/squash result and no task-owned PR remains open.

- [ ] **Step 6: Follow existing auto-deploy gate to exact merge SHA**

Verify deployment through the existing `amazon-returns-deploy.service`/timer only. Do not bypass with manual production copies.

- [ ] **Step 7: Install controller in audit-only mode on the pilot Linux host**

Create a non-secret config referencing authorized repository paths and state root; keep `auto_dispatch=false`. Install and start the 30-minute timer. Verify service/timer active and a manual one-shot reconciliation succeeds.

- [ ] **Step 8: Execute the initial real read-only audit**

Collect the resulting queue and classify every discovered existing branch/worktree/finding as `ACTIVE`, `NEEDS_RESUME`, `READY_FOR_PR`, `PR_BLOCKED`, `BLOCKED_EXTERNAL`, `SUPERSEDED`, `MERGED_UNVERIFIED`, `DONE`, or `ORPHAN_UNKNOWN`. Do not delete or reset anything during this pass.

- [ ] **Step 9: Execute pilot interruption/recovery proof**

Create one disposable controller-managed task/worktree, claim it, make a safe test-only change, stop the worker/allow lease expiry, verify `AGENT_LOST -> NEEDS_RESUME`, render the Resume Packet, claim from a new session, finish the disposable change, validate it, and cleanly reconcile it through the normal Git lifecycle. Do not use production business writes for this proof.

- [ ] **Step 10: Enable automatic dispatch only if all safety gates pass**

Required evidence: lease concurrency tests green, audit-only non-mutation green, redaction tests green, worktree isolation green, pilot interruption/recovery green, and no secret findings. Then set `auto_dispatch=true` only for explicitly configured agents/hosts.

- [ ] **Step 11: Final acceptance evidence**

Record: initial branch/base SHA, merge SHA, deployed SHA, controller service/timer state, ledger location, count of findings by classification, count of `NEEDS_RESUME`, oldest pending age, pilot recovery result, tests/CI result, and any explicit external blockers. Do not mark controller rollout complete while a controller-owned implementation PR, failed Action, failed deployment, or unverified pilot remains.

---

## Plan Self-Review

- Spec coverage: ledger, status/classification separation, worktree isolation, heartbeat/lease, safe checkpoints, local Git scanner, GitHub reconciliation, deterministic classifier, Resume Packet, prioritized queue, guarded fallback dispatcher, persistent 30-minute controller, initial audit, observability inputs, security constraints, and `MERGED + VERIFIED` completion are all mapped to tasks above.
- Placeholder scan: no `TBD`, `TODO`, or unspecified implementation steps remain.
- Type/interface consistency: `TaskRecord`, `GitFinding`, `RemoteState`, `ClassificationDecision`, `Ledger`, and Resume Packet interfaces are introduced before downstream use and retain the same names across tasks.
- Rollout safety: implementation defaults to audit-only and `auto_dispatch=false`; existing dirty/shared checkouts are observed, not migrated or mutated automatically.
