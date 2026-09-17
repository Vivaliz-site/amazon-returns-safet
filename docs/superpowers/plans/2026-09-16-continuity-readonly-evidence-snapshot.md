# Continuity Read-Only Evidence Snapshot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the recurring Gemini worker's direct SQLite ledger read with an atomic, digest-bound, controller-produced read-only evidence snapshot, then complete the real pilot before enabling automatic dispatch.

**Architecture:** The controller/dispatcher remains the only writer of the WAL ledger. After claiming a task lease, it serializes exactly the worker validation fields into a non-secret JSON evidence artifact beside the existing resume packet, writes it atomically, and binds its SHA-256/path into the strict job envelope. The worker validates and reads that immutable artifact without opening SQLite; systemd makes the controller state directory inaccessible to the worker while preserving the existing narrow queue/worktree write paths.

**Tech Stack:** Python 3 stdlib (`sqlite3`, `dataclasses`, `json`, `hashlib`, `pathlib`), unittest, systemd, Git/GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-16-recurring-gemini-worker-isolation-design.md`

## Global Constraints

- `auto_dispatch` stays `false` until the complete disposable pilot is green.
- Automatic providers remain only `gemini` and separately certified `rooter`; `rooter.enabled=false` until certified.
- Codex/ChatGPT/Claude remain manual/interactive only.
- Worker must never obtain write access to `/var/lib/agent-continuity/controller` or broader `ReadWritePaths`.
- Do not copy provider, GitHub, publisher, OpenAI, Claude, cookie, or other secrets into jobs/snapshots.
- Use isolated worktree, TDD, independent review, complete continuity suite, repository-required gates, exact-SHA deploy verification, and real pilot evidence.

---

### Task 1: Reproduce WAL read-only failure and define snapshot contract

**Files:**
- Modify: `tests/continuity/test_worker.py`
- Modify: `tests/continuity/test_job_queue.py`

**Interfaces:**
- Consumes: current `Ledger`, `JobEnvelope`, `run_one_job`.
- Produces: failing tests requiring a digest-bound task evidence artifact and proving worker execution does not require ledger access.

- [ ] Add a deterministic characterization test that creates a WAL database and demonstrates the read-only SQLite failure mode under a non-writable WAL/SHM boundary.
- [ ] Add a desired-behavior worker test where direct ledger access is unavailable but a valid evidence snapshot exists; verify this test fails before production changes.
- [ ] Add strict queue tests for evidence path/digest validation, tamper rejection, atomic `0640` artifact mode, and secret-like field absence.
- [ ] Run the focused tests and record the expected RED failure reason.

### Task 2: Implement controller-produced immutable task evidence

**Files:**
- Modify: `tools/continuity/job_queue.py`
- Modify: `tools/continuity/dispatcher.py`
- Modify: `tools/continuity/worker.py`
- Modify: `scripts/run-continuity-pilot.py`
- Modify: `tests/continuity/test_dispatcher.py`
- Modify: `tests/continuity/test_publisher.py`
- Modify: `tests/continuity/test_worker.py`
- Modify: `tests/continuity/test_job_queue.py`

**Interfaces:**
- Produces: `TaskEvidenceSnapshot` JSON plus atomic write/load helpers; `JobEnvelope.task_evidence_sha256` and `task_evidence_path`.
- Consumes: a claimed `TaskRecord`; worker receives only non-secret fields required to validate lease/session/repository/worktree/branch/head/dirty state.

- [ ] Revalidate HEAD and literal target blocks immediately before each edit.
- [ ] Add the minimal snapshot dataclass/serialization and atomic writer/loader using the existing queue atomic-write primitive.
- [ ] Have automatic dispatcher and pilot write the snapshot only after a successful ledger claim, then include path/digest in the strict job envelope.
- [ ] Replace worker SQLite `_read_task_evidence` with snapshot loading, root/path containment checks, digest verification, strict task/session identity checks, and existing dirty/head validations.
- [ ] Keep snapshot fields bounded/non-secret and fail closed on missing, malformed, outside-root, or digest-mismatched evidence.
- [ ] Run focused tests to GREEN.

### Task 3: Harden the OS trust boundary

**Files:**
- Modify: `deploy/systemd/agent-continuity-worker.service`
- Modify: `tests/continuity/test_systemd_contract.py`

**Interfaces:**
- Produces: worker has explicit read-only access to packets/evidence but `InaccessiblePaths=/var/lib/agent-continuity/controller`; existing worker queue/worktree write paths remain unchanged.

- [ ] Add a failing systemd contract assertion for an inaccessible controller state directory and unchanged `ReadWritePaths`.
- [ ] Make the minimal unit change; do not broaden write paths or credential exposure.
- [ ] Run systemd contract and continuity tests to GREEN.

### Task 4: Validate, review, integrate, and deploy exact SHA

**Files:**
- Modify only if review or validation finds a demonstrated defect.

- [ ] Run syntax/compile, focused tests, full `tests/continuity`, complete repository suite, tenant SQL audit, shell syntax, and `git diff --check`.
- [ ] Inspect final diff for secrets, unexpected files, weakened gates, and concurrent changes.
- [ ] Perform independent review against the approved recurring-worker spec and task constraints; correct demonstrated findings and repeat tests.
- [ ] Commit, push, open PR, require CI for the exact head, merge only that validated head, and confirm no task-owned PR/check remains pending.
- [ ] Follow repository auto-deploy until `/opt/agent-continuity/.source-sha` and deployed main match the merge SHA; reprovision only if the unit/runtime needs it.

### Task 5: Real pilot, enablement gate, and first recurring cycle

**Files:**
- Runtime/operator-owned config only after pilot success; no secret material enters Git.

- [ ] Before pilot, prove `auto_dispatch=false`, worker cannot write/open the controller ledger, worker/path and publisher/path are installed/enabled, and rooter remains disabled.
- [ ] Run the disposable continuity pilot end-to-end and require `pilot-evidence.json` status `green`, GEMINI provider, PR/CI/merge, exact deployed SHA, receipt/token telemetry, and secret-boundary evidence.
- [ ] Only after green pilot run `scripts/enable-continuity-auto-dispatch.py` with the exact runtime source SHA and pilot evidence.
- [ ] Verify `auto_dispatch=true`, automatic candidates are only Gemini/rooter, `rooter.enabled=false`, and no Codex/ChatGPT/Claude automatic path exists.
- [ ] Verify controller timer, worker path, publisher path, then observe the first real scheduled recurring controller cycle and confirm no Codex/Claude automatic consumption plus task/lease/job/receipt digests and telemetry.
- [ ] Execute the mandatory extreme-audit/runtime-parity/overlay gates applicable to this material worker/scheduler change and update audit status if the formal audit completes.
