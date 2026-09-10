# Seller Central Event-Driven Wake Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute eligible Seller Central writes immediately when a known business date becomes due, without a resident browser or short-interval external polling, while allowing only bounded safe pre-write retries.

**Architecture:** The PHP runtime writes a non-secret atomic wake marker only after an eligible Seller Central outbox write is pending. A `systemd.path` unit starts the existing short-lived browser service immediately. Event-triggered cycles track `PRE_WRITE` versus `WRITE_PHASE`; only transient `PRE_WRITE` failures may arm a local 30-minute retry, with three total event attempts. The existing 08:00/20:00 timer remains as routine/fallback execution.

**Tech Stack:** PHP 8+, MySQL-backed tenant-scoped outbox, Bash, Node.js Seller Central workers, systemd service/path/timer units, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-10-seller-central-event-driven-wake-design.md`

## Global Constraints

- Preserve D+45, financial reconciliation, Amazon live eligibility, tenant/connection scoping, deterministic idempotency keys and read-back requirements.
- Do not create a permanent browser or external five-minute/30-minute polling loop.
- Routine business/browser cadence remains 12 hours, with Seller Central timer at 08:00 and 20:00 America/Sao_Paulo.
- Event retry is local-only, 30 minutes, maximum three total event attempts (initial + two retries).
- Never automatically event-retry after `WRITE_PHASE`, `retry_safe=false`, `HUMAN_CHALLENGE`, CAPTCHA/MFA requiring intervention or `UI_DRIFT`.
- Wake/retry files contain no order number, case ID, customer data, messages, evidence, credentials, token, cookie, OTP or TOTP seed.
- Production changes deploy only through the existing auto-gate.
- Every behavior change is regression-first TDD and requires functional production acceptance.

---

### Task 1: Application-side wake primitive

**Files:**
- Create: `includes/amazon-returns/SellerCentralWake.php`
- Modify: `includes/amazon-returns/Runtime.php` or the smallest existing scheduling integration point that knows whether a Seller Central write is pending
- Modify if required for dependency loading only: `workers/amazon-returns/daemon.php`
- Test: `tests/seller-central-event-wake-test.php`

**Interfaces:**
- Consumes: configured wake path from `AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE`; current bridge mode; scoped outbox state.
- Produces: `SvAmazonSellerCentralWake::request(string $source='known-action'): bool`, returning true only when an atomic marker was created/refreshed successfully.

- [ ] **Step 1: Write the failing regression test**

Create a focused test proving that the wake helper is disabled when the env/path is absent, writes an atomic JSON marker when configured, and rejects business payload leakage. The marker schema must contain only `version`, `requested_at`, `source`, and `attempt`.

- [ ] **Step 2: Run the focused test and observe failure**

Run: `php tests/seller-central-event-wake-test.php`
Expected: FAIL because `SellerCentralWake.php` and/or wake integration do not yet exist.

- [ ] **Step 3: Implement the minimal wake helper**

Implement a focused final class that validates the configured path is under the expected shared wake directory, creates the directory only when already provisioned/allowed, writes to a temporary file with restrictive permissions, and publishes via atomic rename. No business identifiers are accepted by the API.

- [ ] **Step 4: Integrate wake request after eligible outbox scheduling**

Only request the marker when bridge mode is `polling` and at least one active/pending action is one of `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, `SELLER_SUPPORT_OPEN`, `SELLER_SUPPORT_UPDATE`. Do not wake Seller Central for Gmail actions or an idempotent action already completed/superseded.

- [ ] **Step 5: Run focused and runtime tests**

Run: `php tests/seller-central-event-wake-test.php && php tests/amazon-returns-runtime-test.php && php tests/known-deadline-wake-test.php`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit message: `feat: signal Seller Central browser for due writes`

---

### Task 2: Event-aware browser wrapper and phase safety

**Files:**
- Modify: `scripts/amazon-returns/run-seller-central-daily.sh`
- Create: `scripts/amazon-returns/run-seller-central-event.sh`
- Test: `tests/seller-central-event-runner-test.php`

**Interfaces:**
- Consumes: `SELLER_CENTRAL_WAKE_FILE`, `SELLER_CENTRAL_EVENT_STATE_DIR`, existing browser env and workers.
- Produces: phase file containing only `PRE_WRITE` or `WRITE_PHASE`; retry-state JSON containing only `version`, `attempt`, `next_at`, `reason_class`.

- [ ] **Step 1: Write a failing shell-contract test**

Assert that event mode consumes the wake marker, records `PRE_WRITE` before browser/auth work, transitions to `WRITE_PHASE` immediately before invoking `seller-central-bridge-worker.mjs`, and never writes a business payload into state files.

- [ ] **Step 2: Run the test and observe failure**

Run: `php tests/seller-central-event-runner-test.php`
Expected: FAIL because the event runner and phase hook do not exist.

- [ ] **Step 3: Add the phase hook to the existing daily runner**

Support an optional phase-file environment variable. Write `PRE_WRITE` at startup and `WRITE_PHASE` immediately before the bridge write worker. Keep the normal twice-daily path behavior unchanged when no event phase file is configured.

- [ ] **Step 4: Implement event wrapper**

The wrapper must atomically consume the wake marker, derive attempt from local state, invoke the existing daily runner, and inspect the final phase on failure. Only failures before `WRITE_PHASE` are candidates for local retry. Human challenge/UI-drift markers or ambiguous write-phase failures must not arm local replay.

- [ ] **Step 5: Run shell syntax and focused tests**

Run: `bash -n scripts/amazon-returns/run-seller-central-daily.sh scripts/amazon-returns/run-seller-central-event.sh && php tests/seller-central-event-runner-test.php`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit message: `feat: guard event browser retries by write phase`

---

### Task 3: Local retry checker with three-attempt cap

**Files:**
- Create: `scripts/amazon-returns/check-seller-central-retry.sh`
- Test: `tests/seller-central-event-retry-test.php`

**Interfaces:**
- Consumes: local retry-state JSON and wake marker path only.
- Produces: a recreated wake marker when `next_at <= now` and attempt <= 3; otherwise no external action.

- [ ] **Step 1: Write failing retry tests**

Cover: no retry file => no wake; future `next_at` => no wake; due attempt 2 => wake; due attempt 3 => wake; attempt >3/corrupt state => fail closed; state contains only allowed operational fields.

- [ ] **Step 2: Run the focused test and observe failure**

Run: `php tests/seller-central-event-retry-test.php`
Expected: FAIL because checker does not exist.

- [ ] **Step 3: Implement the checker**

Use local file parsing only. It must not call curl, node, browser binaries, Amazon endpoints, Gmail, SP-API or the application bridge. A due safe retry only writes the normal marker and clears/advances retry state so the path unit handles execution.

- [ ] **Step 4: Verify retry contract**

Run: `bash -n scripts/amazon-returns/check-seller-central-retry.sh && php tests/seller-central-event-retry-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: add bounded local Seller Central wake retries`

---

### Task 4: systemd event dispatch units

**Files:**
- Create: `deploy/systemd/amazon-returns-seller-central-browser.path`
- Create: `deploy/systemd/amazon-returns-seller-central-retry.service`
- Create: `deploy/systemd/amazon-returns-seller-central-retry.timer`
- Modify: `deploy/systemd/amazon-returns-seller-central-browser.service`
- Modify: `deploy/systemd/amazon-returns-safet.service`
- Test: `tests/seller-central-event-systemd-test.php`

**Interfaces:**
- Path watches `/home/ubuntu/amazon-returns-deploy/shared/seller-central-wake/wake.json`.
- Browser service invokes `run-seller-central-event.sh` when wake marker exists, otherwise preserves normal daily behavior.
- Retry timer cadence is 30 minutes and starts only the local retry-check service.

- [ ] **Step 1: Write failing unit-contract tests**

Assert exact `PathExists`, service target, writable shared paths, environment variables, retry timer `OnUnitActiveSec=30min` (or semantically exact 30-minute cadence), and preservation of the 08:00/20:00 routine timer.

- [ ] **Step 2: Run the test and observe failure**

Run: `php tests/seller-central-event-systemd-test.php`
Expected: FAIL because units are absent.

- [ ] **Step 3: Add path/retry units and service integration**

Use systemd serialization; do not add a permanently active browser. Ensure a marker written while the browser service is running remains to trigger one follow-up cycle after completion.

- [ ] **Step 4: Run focused tests and syntax checks**

Run: `php tests/seller-central-event-systemd-test.php && systemd-analyze verify deploy/systemd/amazon-returns-seller-central-browser.service deploy/systemd/amazon-returns-seller-central-browser.path deploy/systemd/amazon-returns-seller-central-retry.service deploy/systemd/amazon-returns-seller-central-retry.timer 2>/dev/null || true`
Expected: PHP contract PASS; any environment-specific systemd warnings are reviewed rather than ignored.

- [ ] **Step 5: Commit**

Commit message: `feat: wake Seller Central browser with systemd path`

---

### Task 5: Production provisioning and safe enablement

**Files:**
- Modify: `scripts/provision-production.sh`
- Modify: `scripts/provision-seller-central-browser-host.sh`
- Test: `tests/seller-central-event-provision-test.php`

**Interfaces:**
- Creates `/home/ubuntu/amazon-returns-deploy/shared/seller-central-wake` with owner/group permitting `www-data` to signal and `ubuntu:www-data` browser service to consume.
- Installs/enables path and retry timer only when browser prerequisites pass the existing readiness gate.

- [ ] **Step 1: Write failing provisioning tests**

Assert directory ownership/mode commands, unit installation, daemon-reload, enable/disable behavior tied to the existing browser readiness decision, and no browser credential/token material copied into wake files.

- [ ] **Step 2: Run focused test and observe failure**

Run: `php tests/seller-central-event-provision-test.php`
Expected: FAIL before provisioning code changes.

- [ ] **Step 3: Implement provisioning**

Install new units from the deployed release, create shared directories, export the wake-file path to the PHP service, and enable path/retry timer only alongside a valid browser runtime. Missing prerequisites disable these helper units without failing core app deployment.

- [ ] **Step 4: Run focused tests and shell syntax**

Run: `bash -n scripts/provision-production.sh scripts/provision-seller-central-browser-host.sh && php tests/seller-central-event-provision-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

Commit message: `feat: provision event-driven Seller Central wake`

---

### Task 6: Project memory, full regression suite and independent review

**Files:**
- Modify: `docs/MEMORIA-DO-PROJETO.md`
- Modify if necessary: `docs/REGRAS-DE-ENTREGA.md`
- Test: all existing tests plus new event-wake tests.

**Interfaces:**
- Records the approved operational rule without superseding unrelated business policy.

- [ ] **Step 1: Record final approved rule**

Document immediate local event wake, short-lived browser, 08:00/20:00 fallback, 30-minute safe pre-write retry and three-total-attempt cap. Explicitly prohibit replay after write uncertainty/human challenge/UI drift.

- [ ] **Step 2: Run full validation**

Run:
`set -e; for test in tests/*.php; do php "$test"; done; php scripts/audit-tenant-sql.php; find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null; node --check scripts/amazon-returns/safe-t-status-parser.mjs; node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs; node --check scripts/amazon-returns/seller-central-bridge-worker.mjs; bash -n scripts/auto-deploy.sh scripts/provision-production.sh scripts/provision-seller-central-browser-host.sh scripts/amazon-returns/run-seller-central-daily.sh scripts/amazon-returns/run-seller-central-event.sh scripts/amazon-returns/check-seller-central-retry.sh; git diff --check`
Expected: all PASS.

- [ ] **Step 3: Independent task/whole-branch review**

Reviewer must specifically inspect lost-wake races, duplicate browser starts, unsafe replay after uncertain writes, secret leakage, permissions, tenant boundaries and interaction with the existing outbox retry semantics.

- [ ] **Step 4: Fix every load-bearing finding and rerun validation**

No unresolved correctness/security finding may be carried into merge.

- [ ] **Step 5: Commit documentation/review fixes**

Commit message: `docs: record event-driven Seller Central dispatch rule`

---

### Task 7: PR, CI, merge, auto-deploy and production acceptance

**Files:**
- No new application file unless a production finding requires a TDD fix.

**Interfaces:**
- GitHub Actions and existing `scripts/auto-deploy.sh` gate.

- [ ] **Step 1: Open PR from the validated implementation branch**

Include test evidence and explicit statement that no external write was generated solely for testing.

- [ ] **Step 2: Verify CI for the exact PR head**

Required result: Amazon Returns CI success for the exact head SHA. Resolve any failure with a new regression/fix commit and re-run review.

- [ ] **Step 3: Merge validated head**

Do not bypass required checks. Confirm no task-owned PR remains open.

- [ ] **Step 4: Follow auto-deploy to exact merge SHA or a verified descendant**

Confirm `.release-sha`, service active state and deploy journal. If a newer main release is deployed, prove the merge SHA is its ancestor.

- [ ] **Step 5: Functional production acceptance without a real write side effect**

Verify path and timers active. Create a safe non-business wake marker that results in exactly one serialized browser cycle; confirm Chromium terminates. Exercise retry logic with a controlled pre-write failure mode that cannot reach Seller Central submission, verify 30-minute scheduling semantics and cap, then verify a controlled write-phase simulation does not arm retry. Do not submit a claim, appeal or support case just to test dispatch.

- [ ] **Step 6: Validate live business state**

Inspect pending/processing/dead-letter counts, browser/service logs, health endpoint and known-date scheduler state. If an independently eligible existing real outbox write is already pending, allow normal production processing under its existing write gate and verify read-back; otherwise do not create one.

- [ ] **Step 7: Visual/operational audit**

Re-run cockpit desktop/mobile render checks only if production release contains UI changes from concurrent merges; otherwise confirm this infrastructure change did not alter public UI assets. Record any actionable improvement and implement it through a separate bounded TDD change only if it is directly related and safe.

- [ ] **Step 8: Final repository audit**

Confirm no task-owned open PR, failed/pending required Action, dirty task worktree, unpushed commit or unresolved conflict remains. Report merge SHA, deployed SHA, CI result, tests and production acceptance evidence.
