# Amazon Returns Global Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate, repair, deploy and production-certify the complete Amazon Returns / SAFE-T scope with real end-to-end evidence.

**Architecture:** Reconcile current `origin/main` with already-developed subsystem branches, diagnose production blockers from real runtime evidence, and remediate each defect with TDD in one isolated worktree. External effects are enabled one channel at a time and certified only after destination readback/reconciliation.

**Tech Stack:** PHP 8.x, MySQL, Node.js CDP workers, systemd, GitHub Actions, Amazon SP-API/Finances/Reports, Gmail API, Tiny/Olist browser/API integration.

**Spec:** `docs/superpowers/specs/2026-09-16-amazon-returns-global-scope-design.md`

## Global Constraints
- ShopVivaliz first SAFE-T opening trigger is D+45 from the Amazon-issued customer refund, with live eligibility and documented blockers only.
- ERP sales-return creation is active only for refunds within the latest 30 days, measured from refund_at. Older refunds are outside the active ERP-return scope. Other recovery workflows keep their own explicit policy windows.
- `RECOVERED` requires real reconciled financial credit; promise/approval/submission is insufficient.
- Damaged/discrepant returns require the user's initial SAFE-T opening; automation may continue once a SAFE-T ID exists.
- Normal production must not depend on Fred-Win or KOCEPSV; they are fallback/admin paths only.
- Business polling cadence is 12 hours except immediate manual lookup and persisted known-deadline wakeups.
- Official auto-deploy is the only normal deployment path.
- No critical external write is certified without destination readback or independent reconciliation.

---

### Task 1: Reconstruct current production and repository state

**Files:**
- Read: `AGENTS.md`
- Read: `AUDIT_POLICY.md`
- Read: `AUDIT_EXTREMA.md`
- Read: `docs/quality/AUDIT_STATUS.md`
- Modify when evidence changes: `docs/quality/AUDIT_STATUS.md`

**Interfaces:**
- Consumes: current GitHub/main, release SHA, systemd state, health, DB/outbox state.
- Produces: factual baseline used by every later task.

- [ ] **Step 1: Prove source and deployment provenance**

```bash
git fetch --prune origin
git status --short --branch
git rev-parse HEAD origin/main
cat /home/ubuntu/amazon-returns-deploy/current/.release-sha
gh pr list --repo Vivaliz-site/amazon-returns-safet --state open
gh run list --repo Vivaliz-site/amazon-returns-safet --limit 20
```

- [ ] **Step 2: Prove baseline validation is green**

```bash
set -e
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
node --check admin/amazon-returns/assets/cockpit-operational.js
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
```

- [ ] **Step 3: Read production health and recent service/deploy evidence**

```bash
systemctl show amazon-returns-safet.service amazon-returns-deploy.timer -p Id -p ActiveState -p SubState -p UnitFileState -p Result
curl --fail --silent --show-error https://returns.shopvivaliz.com.br/api/health.php
journalctl -u amazon-returns-safet.service -n 250 --no-pager
journalctl -u amazon-returns-deploy.service -n 120 --no-pager
```

- [ ] **Step 4: Run the canonical live tenant verification under an authorized read-only/admin path**

```bash
sudo -n /home/ubuntu/amazon-returns-deploy/current/scripts/verify-live-tenant-foundation.sh
```

Expected evidence: current active-case count, PROCESSING/stale rows, outbox, dead letters, tenant scoping, and runtime binding without changing data.

### Task 2: Integrate Gmail incremental catch-up

**Files:**
- Modify/merge: `includes/amazon-returns/GmailApi.php`
- Modify/merge: `includes/amazon-returns/Runtime.php`
- Modify/merge: `includes/amazon-returns/BridgeService.php`
- Modify/merge: `workers/amazon-returns/daemon.php`
- Test: `tests/gmail-incremental-catchup-test.php`
- Test: `tests/gmail-catchup-bridge-gate-test.php`
- Test: `tests/gmail-api-rate-limit-backoff-test.php`
- Test: `tests/email-executor-gates-test.php`
- Test: `tests/decision-safe-order-test.php`

**Interfaces:**
- Consumes: Gmail History API cursor and metadata.
- Produces: monotonic checkpoint plus `gmail_catchup_pending`/`has_more` write-gating state.

- [ ] **Step 1: Reconcile the existing branch with current main without losing concurrent fixes**

```bash
git log --oneline origin/main..feat/gmail-incremental-catchup-20260916
git diff --check origin/main...feat/gmail-incremental-catchup-20260916
git merge --no-ff feat/gmail-incremental-catchup-20260916
```

- [ ] **Step 2: Run the Gmail RED/GREEN regression set on the merged tree**

```bash
php tests/gmail-incremental-catchup-test.php
php tests/gmail-catchup-bridge-gate-test.php
php tests/gmail-api-rate-limit-backoff-test.php
php tests/email-executor-gates-test.php
php tests/decision-safe-order-test.php
```

- [ ] **Step 3: Run the full suite and tenant SQL audit**

```bash
set -e
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
```

- [ ] **Step 4: Deploy through PR/CI/auto-gate and prove production catch-up**

Observe consecutive production cycles and persist evidence that cursor advances only after complete ingest, `has_more=true` schedules safe continuation, writes remain gated, restart preserves pending state, final `has_more=false` clears the gate, and normal 12-hour cadence resumes.

### Task 3: Diagnose and clear functional health blockers

**Files:**
- Inspect/modify if root cause requires: `includes/amazon-returns/BusinessHealth.php`
- Inspect/modify if root cause requires: `includes/amazon-returns/OperationalHealth.php`
- Inspect/modify if root cause requires: `includes/amazon-returns/CockpitHealth.php`
- Test: `tests/business-health-operational-blocker-test.php`
- Test: `tests/operational-health-runtime-contract-test.php`
- Test: `tests/public-health-contract-test.php`

**Interfaces:**
- Consumes: write profile, worker freshness, auth/session state, queue/backlog, readback/reconciliation freshness.
- Produces: `OK` only when mandatory capabilities are operational; otherwise `DEGRADED` with traceable reason internally.

- [ ] **Step 1: Identify each concrete reason behind current `DEGRADED` from runtime logs/state**
- [ ] **Step 2: For each code defect, add a failing regression test that reproduces the incorrect health classification**
- [ ] **Step 3: Implement the minimum root-cause fix and verify the focused test GREEN**
- [ ] **Step 4: Re-run health suites and production probe; never force `OK` by suppressing a real blocker**

### Task 4: Prove ERP Tiny/Olist sales-return creation and reconcile backlog

**Files:**
- Inspect/modify: `includes/amazon-returns/ErpSalesReturnGateway.php`
- Inspect/modify: `includes/amazon-returns/ErpSalesReturnService.php`
- Inspect/modify: `workers/amazon-returns/daemon.php`
- Inspect/modify: `deploy/write-profile.json`
- Test: `tests/erp-sales-return-workflow-test.php`
- Test: `tests/erp-return-invoice-dedupe-test.php`
- Test: `tests/erp-write-flag-effective-gate-test.php`
- Test: `tests/erp-sales-return-ui-contract-test.php`

**Interfaces:**
- Consumes: applicable refund + ERP sale/invoice correlation.
- Produces: one ERP sales return per applicable refund, external readback, return invoice reference, durable idempotent link.

- [ ] **Step 1: Prove authenticated ERP browser/API readiness without relying on local PCs**
- [ ] **Step 2: Query current write-profile gates and eligible ERP backlog read-only**
- [ ] **Step 3: If any ERP defect is found, reproduce it with a failing focused test before changing production code**
- [ ] **Step 4: Enable both ERP gates in the controlled release only after readiness passes**
- [ ] **Step 5: Execute one real canary and read it back from Tiny/Olist**
- [ ] **Step 6: Reconcile every applicable active refund; prove no duplicate and no missing ERP return**
- [ ] **Step 7: Keep legacy ERP records explicit and never synthesize invoice numbers**

### Task 5: Prove SAFE-T, Seller Support, and Finance lifecycles

**Files:**
- Inspect/modify: `includes/amazon-returns/SafeTDecisionEngine.php`
- Inspect/modify: `includes/amazon-returns/ReturnActionRouter.php`
- Inspect/modify: `scripts/amazon-returns/seller-central-adapter.mjs`
- Inspect/modify: `scripts/amazon-returns/seller-central-bridge-worker.mjs`
- Test: `tests/seller-support-readback-diagnostics-test.php`
- Test: `tests/classic-fba-support-open-idempotency-test.php`
- Test: `tests/financial-credit-safety-test.php`
- Test: `tests/serrac-reimbursement-reconciliation-test.php`

**Interfaces:**
- Consumes: eligibility, current case/thread, Amazon response, financial events.
- Produces: idempotent SAFE-T/support writes, readback, retries, and only reconciled financial recovery.

- [ ] **Step 1: Resolve or explicitly supersede open PR #239 after comparing its behavior with the newer main implementation**
- [ ] **Step 2: Reproduce any current Seller Support/UI_DRIFT/readback failure before patching**
- [ ] **Step 3: Prove SearchForCases/ViewCase/readback with bounded pacing and no duplicated case/thread**
- [ ] **Step 4: Prove SAFE-T submit/appeal lifecycle on eligible existing flows without violating the damaged-return manual-first rule**
- [ ] **Step 5: Prove Finance pagination/retry/partial-credit behavior and scheduler decision production after valid refresh**

### Task 6: Prove operator UI, intake, consultation, review, and mobile behavior

**Files:**
- Inspect/modify: `admin/amazon-returns/index.php`
- Inspect/modify: `admin/amazon-returns/api/intake.php`
- Inspect/modify: `admin/amazon-returns/api/case.php`
- Inspect/modify: `admin/amazon-returns/api/cases.php`
- Inspect/modify: `admin/amazon-returns/assets/cockpit-operational.js`
- Inspect/modify: `admin/amazon-returns/assets/cockpit-operational.css`
- Test: `tests/intake-reference-search-test.php`
- Test: `tests/invoice-intake-live-lookup-contract-test.php`
- Test: `tests/case-reference-search-test.php`
- Test: `tests/cockpit-ui-contract-test.php`
- Test: `tests/customer-nonreceipt-delivery-contradiction-test.php`

**Interfaces:**
- Consumes: operator search/intake/review actions.
- Produces: correct durable backend state and immediately refreshed operator view.

- [ ] **Step 1: Execute real UI searches by order, sales invoice, return invoice, SAFE-T/TBR**
- [ ] **Step 2: Execute missing-local-order manual lookup and prove immediate Amazon sync before not-found**
- [ ] **Step 3: Exercise physical receipt quantity/idempotency/concurrency and reload/revisit persistence**
- [ ] **Step 4: Exercise deterministic non-receipt-vs-delivery flow and prove no unnecessary human review**
- [ ] **Step 5: Exercise server-side filters, pagination, deadlines, session expiry/return, desktop and mobile; fail on unexpected 5xx/pageerror/requestfailed/console.error**

### Task 7: Audit all active cases and autonomy

**Files:**
- Read/modify only if defects require: `workers/amazon-returns/daemon.php`
- Read/modify only if defects require: `workers/amazon-returns/scheduler.php`
- Read/modify only if defects require: `scripts/amazon-returns/run-seller-central-daily.sh`
- Test: `tests/production-autonomy-config-test.php`
- Test: `tests/vm-primary-independence-test.php`
- Test: `tests/daemon-task-clock-contract-test.php`

**Interfaces:**
- Consumes: complete active production case set under current <=90-day rule.
- Produces: case-by-case evidence matrix and corrected autonomous runtime.

- [ ] **Step 1: Determine current active case count from production DB and export only non-secret audit identifiers/state fields**
- [ ] **Step 2: Reconstruct each case across refund, return, tracking, physical receipt, Gmail, SP-API, Finances, Returns, SAFE-T, Seller Support, ERP, outbox, decision, deadline and result**
- [ ] **Step 3: Classify and remediate every stopped/missing/duplicate/incorrect action; every audit escape gets a durable regression guard**
- [ ] **Step 4: Prove normal daemon/timers/schedulers/workers continue with Fred-Win and KOCEPSV unavailable as primary dependencies**
- [ ] **Step 5: Confirm browser workers are on-demand and no unnecessary permanent hidden browser/process remains**

### Task 8: Final delivery, contradictory audit, and certification matrix

**Files:**
- Modify: `docs/quality/AUDIT_STATUS.md`
- Create: `docs/quality/AUDIT_EVIDENCE_2026-09-16_GLOBAL.md`

**Interfaces:**
- Consumes: all task evidence.
- Produces: final requirement -> implementation -> automated test -> functional proof -> external readback -> production -> status matrix.

- [ ] **Step 1: Run full CI-equivalent validation and `git diff --check`**
- [ ] **Step 2: Push branch, open/update PR, obtain required checks/review, merge only validated head**
- [ ] **Step 3: Follow official auto-deploy until exact merge SHA (or a verified descendant) is live**
- [ ] **Step 4: Re-run production E2E and contradictory failure/idempotency/retry paths on the deployed SHA**
- [ ] **Step 5: Confirm no task-owned/open obsolete PR, failed required Action, stuck queue, material dead letter, stale PROCESSING, or unvalidated critical requirement remains**
- [ ] **Step 6: Update `AUDIT_STATUS.md` and evidence matrix; use `APTO` only if every material requirement is proved**
