# Amazon Returns SAFE-T Isolation Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract Amazon Returns / SAFE-T into a fully independent production application on the existing VM, finish all pending correctness fixes, migrate historical state without duplicate writers, and validate the real end-to-end recovery lifecycle.

**Architecture:** Build `Vivaliz-site/amazon-returns-safet` as the sole owner of Amazon Returns, with its own DB bootstrap, runtime config, admin authentication, API, workers, deployment, systemd unit and CI. Preserve the existing append-only event model and outbox semantics, but separate Seller Central reads, Seller Central writes and Gmail writes into channel-specific workers. Cut over with shadow/read-only validation first, freeze old writers before final migration, then enable write channels one at a time with read-back verification.

**Tech Stack:** PHP 8.3, Node.js 24, MySQL 8/MariaDB-compatible SQL, Gmail API OAuth, Amazon SP-API, Seller Central CDP worker on Fred-Win, systemd, nginx/Apache reverse proxy, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-09-04-amazon-returns-safet-isolation-design.md`

## Global Constraints

- Target repository: `Vivaliz-site/amazon-returns-safet`.
- Target admin/API host: `https://returns.shopvivaliz.com.br`.
- Keep the current production VM; isolate filesystem, service, database, secrets and deploy lifecycle.
- New application must not depend on ShopVivaliz runtime includes, tables, admin sessions, deployment symlinks or `.env` after cutover.
- `Data Devolucao` / warehouse receipt hard-blocks a new non-return SAFE-T.
- `Data Reembolso` is financial history only and never proves physical receipt.
- Initial eligibility is D+75.
- First actionable SAFE-T denial goes through Seller Central appeal before detailed review email.
- Denied Seller Central appeal sends exactly one case-scoped detailed review to `Safe-T-Review@amazon.com`.
- A negative email response is analyzed before choosing `RESPOND_EMAIL`, `OPEN_SUPPORT`, `WAIT`, `CREDIT_PENDING`, `CLOSED_LOSS` or `HUMAN_REVIEW`.
- Unknown/ambiguous external responses fail closed.
- Approval is not terminal until Finances reconciliation proves the economic credit.
- `DEFERRED_RELEASED` and later `RELEASED` representations of the same economic lifecycle must not double-count.
- No external writer is enabled until its prerequisites and idempotency/read-back tests pass.
- Never run old and new external writers at the same time.
- No secrets, OAuth tokens, cookies, MFA values or authorization headers in logs/evidence.

---

### Task 1: Finish and Commit Source Correctness Fixes

**Files:**
- Modify: `scripts/amazon-returns/safe-t-status-parser.mjs`
- Modify: `scripts/amazon-returns/seller-central-safe-t-read-worker.mjs`
- Modify: `includes/amazon-returns/SafeTStatus.php`
- Modify: `includes/amazon-returns/SafeTStatusService.php`
- Modify: `includes/amazon-returns/SafeTDecisionEngine.php`
- Modify: `includes/amazon-returns/GmailApi.php`
- Modify: `includes/amazon-returns/GmailParser.php`
- Modify: `includes/amazon-returns/GmailEventSink.php`
- Create: `includes/amazon-returns/SafeTEmailReview.php`
- Modify: `includes/amazon-returns/Outbox.php`
- Modify: `includes/amazon-returns/PolicySeeder.php`
- Modify: `includes/amazon-returns/SpApiEventSink.php`
- Modify: `includes/amazon-returns/FinancialReconciler.php`
- Modify: `workers/amazon-returns/daemon.php`
- Modify: `workers/amazon-returns/scheduler.php`
- Tests: `tests/amazon-returns-*.php`

**Interfaces:**
- Produces: `SvAmazonSafeTStatusService::nextState(string,string,bool): string` preserving appeal lifecycle.
- Produces: `SvAmazonSafeTEmailReview::compose(array,array): array{to:string,subject:string,body:string}`.
- Produces: `SvAmazonGmailApiClient::sendOnce(string,string,string,string): array` with RFC Message-ID idempotency.
- Produces: `SvAmazonReturnsOutbox::claimBatch(PDO,int,array): array` with kind filtering.
- Produces: D+75 seed definitions and upsert that updates existing policy rows.
- Produces: economic lifecycle deduplication for refund and reimbursement observations.

- [ ] **Step 1: Run the complete Amazon Returns baseline on the correction worktree**

Run all `tests/amazon-returns-*.php`, `node --check` on Seller Central scripts and `php -l` on changed PHP files. Expected: no failures after each already-observed RED→GREEN fix is complete.

- [ ] **Step 2: Add/verify parser regression fixtures**

Tests must prove that a top-level original claim `Motivo` is never used as `decision_text` when a later Amazon decision exists, and that latest appeal denial language sets `appeal_submitted=true` / `appeal_denied=true`.

- [ ] **Step 3: Add/verify email-review workflow tests**

Tests must prove: first denial => `SAFE_T_APPEAL`; denial after appeal => `SAFE_T_EMAIL_REVIEW`; email denial does not automatically close; active support case is not duplicated; Gmail send is idempotent.

- [ ] **Step 4: Add/verify D+75 tests**

Assert all active BR return-not-received policy definitions are 75 days and `PolicySeeder::ensure()` updates existing rows instead of leaving 45/60-day rows unchanged.

- [ ] **Step 5: Add/verify financial lifecycle tests**

Use real-shape fixtures with one `DEFERRED_RELEASED` and one later `RELEASED` refund of the same amount and assert the economic refund is counted once. Mirror the same lifecycle behavior in reimbursement reconciliation.

- [ ] **Step 6: Add/verify CDP disconnect test**

Assert the Windows read worker registers WebSocket `close`/`error` handling that rejects all pending CDP promises so a lost browser cannot silently abandon a `PROCESSING` job.

- [ ] **Step 7: Run full suite and commit the correction branch**

Expected: all Amazon Returns tests green, all syntax checks green, no secrets in diff. Commit the tested corrections before extraction.

### Task 2: Create Independent Repository and Project Skeleton

**Files in target repo:**
- Create: `README.md`
- Create: `composer.json` only if dependencies are required; otherwise avoid unnecessary dependency manager changes.
- Create: `src/Config.php`
- Create: `src/Database.php`
- Create: `src/Domain/*` by extracting the corrected Amazon Returns classes.
- Create: `public/index.php`
- Create: `public/api/bridge.php`
- Create: `public/api/status-bridge.php`
- Create: `bin/daemon.php`
- Create: `bin/migrate.php`
- Create: `scripts/seller-central/*.mjs`
- Create: `tests/*`
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- `App\Config` owns environment access with safe defaults and channel-specific flags.
- `App\Database::connect(): PDO` uses only dedicated DB environment variables.
- Domain code must not `require` ShopVivaliz files.

- [ ] **Step 1: Create private target repository**

Create `Vivaliz-site/amazon-returns-safet` with private visibility and default branch `main`.

- [ ] **Step 2: Copy only the corrected subsystem and tests**

Extract domain/services/workers/tests; do not copy unrelated website code.

- [ ] **Step 3: Write failing independence test**

Test scans runtime source and fails if it contains `site-shopvivaliz`, `shopvivaliz-deploy/current`, `config/constants.php`, `includes/pdo-database.php`, `/admin/amazon-returns`, or direct dependence on website session helpers.

- [ ] **Step 4: Implement dedicated Config/Database bootstrap**

Environment names must be application-specific where useful and compatible with current secret values during migration. `production` mode alone must not enable writes.

- [ ] **Step 5: Run target tests and commit**

Expected: extracted domain tests pass in target repo and independence scan passes.

### Task 3: Independent Schema, State Migration, and Admin Authentication

**Files in target repo:**
- Create: `src/Schema.php`
- Create: `migrations/001_initial.sql`
- Create: `src/Auth/AdminAuth.php`
- Create: `public/login.php`
- Create: `public/logout.php`
- Create: `public/admin/index.php`
- Create: `public/admin/intake.php`
- Create: `public/admin/api/*.php`
- Create: `tests/schema-test.php`
- Create: `tests/auth-test.php`
- Create: `tests/admin-test.php`

**Interfaces:**
- Dedicated DB owns `amazon_return_*` tables.
- Admin auth owns its own secure cookie/session and user identity source; no website session/database dependency.
- Physical intake appends the same authoritative warehouse events used by policy projection.

- [ ] **Step 1: Write schema migration/idempotency tests**

Running migrations twice must preserve tables and not erase event history or idempotency keys.

- [ ] **Step 2: Implement dedicated schema/migrations**

Preserve current primary identifiers and event/outbox keys so migrated state remains referentially stable.

- [ ] **Step 3: Write admin authentication tests**

Unauthenticated admin/API requests fail closed; authenticated session has CSRF protection for physical intake writes.

- [ ] **Step 4: Implement independent admin auth and migrate dashboard/intake UI**

Keep functionality, remove ShopVivaliz admin/session/bootstrap dependencies.

- [ ] **Step 5: Add migration verification command**

`bin/migrate.php --verify` must report table counts, case/event/outbox/DLQ counts, unique-key integrity and selected case hashes without printing secrets.

- [ ] **Step 6: Run tests and commit**

### Task 4: Complete Email Reply Interpretation and Decision Engine

**Files in target repo:**
- Create/Modify: `src/Email/ReviewReplyAnalyzer.php`
- Modify: `src/Domain/GmailParser.php`
- Modify: `src/Domain/GmailEventSink.php`
- Modify: `src/Domain/SafeTDecisionEngine.php`
- Create: `tests/email-reply-analyzer-test.php`
- Modify: `tests/safet-decision-test.php`

**Interfaces:**
- `ReviewReplyAnalyzer::analyze(array $message, array $caseContext): array` returns one of `APPROVED`, `INFO_REQUESTED`, `DENIED_ACTIONABLE`, `DENIED_FINAL`, `WAIT`, `UNKNOWN_AMBIGUOUS` plus evidence-backed reason fields.
- Decision engine maps that result to `RESPOND_EMAIL`, `OPEN_SUPPORT`, `WAIT`, `CREDIT_PENDING`, `CLOSED_LOSS`, or `HUMAN_REVIEW`.

- [ ] **Step 1: Write RED fixtures for each response class**

Include: approval; specific information request; denial containing a factual contradiction; explicit no-more-communications denial without new material fact; proactive reimbursement promise with date; ambiguous generic reply.

- [ ] **Step 2: Implement conservative analyzer**

Use deterministic rules first. Do not infer finality from the word “negado” alone. Preserve raw-message hash and sanitized excerpts rather than fabricating rationale.

- [ ] **Step 3: Write RED workflow tests**

Prove `DENIED_FINAL` only becomes `CLOSED_LOSS` when there is no new material fact, no promised future action, no open procedural channel and no financial inconsistency; otherwise choose a non-terminal path.

- [ ] **Step 4: Implement decision transitions and idempotency**

Outgoing email response keys are deterministic by case/thread/latest reply hash. Support escalation remains one active case per SAFE-T/reason.

- [ ] **Step 5: Run tests and commit**

### Task 5: Channel-Isolated Workers, Health, and Production Deployment

**Files in target repo:**
- Create: `bin/workers/scheduler.php`
- Create: `bin/workers/gmail.php`
- Create: `bin/workers/financial.php`
- Create: `bin/workers/sp-api.php`
- Create: `bin/workers/seller-central-read.php` or remote status API orchestration
- Create: `deploy/amazon-returns-safet.service`
- Create: `deploy/nginx-returns.conf` or equivalent web-server config
- Create: `scripts/install-service.sh`
- Create: `src/Health.php`
- Create: `tests/runtime-test.php`
- Create: `tests/health-test.php`

**Interfaces:**
- Gmail worker claims only Gmail-write jobs.
- Seller Central write worker claims only approved Seller Central write kinds.
- Status bridge can only claim `SAFE_T_READ`.
- Health exposes recent runtime failures, not just static credential readiness.

- [ ] **Step 1: Write worker-isolation tests**

A Gmail worker cannot claim Seller Central writes; a Seller Central worker cannot claim email writes; status bridge cannot claim any write action.

- [ ] **Step 2: Implement isolated workers and runtime health**

Persist last success/failure per source and surface stale heartbeat/auth/runtime failures.

- [ ] **Step 3: Add dedicated systemd/install tests**

Assert working directory and EnvironmentFile use `/home/ubuntu/amazon-returns-deploy/...` and never ShopVivaliz deploy paths.

- [ ] **Step 4: Deploy in read-only/shadow mode on the existing VM**

Create dedicated deploy/shared paths, dedicated DB/user, service, web vhost and TLS endpoint. Leave all external write flags off.

- [ ] **Step 5: Validate `returns.shopvivaliz.com.br`**

Verify authenticated admin access, API heartbeat, DB connectivity, Gmail read, SP-API read and Seller Central read heartbeat without external writes.

- [ ] **Step 6: Commit deployment artifacts**

### Task 6: Historical Data Migration and Shadow Comparison

**Files in target repo:**
- Create: `bin/export-source-state.php` or an equivalent sanitized migration utility
- Create: `bin/import-state.php`
- Create: `bin/shadow-audit.php`
- Create: `tests/migration-test.php`

**Interfaces:**
- Migration preserves IDs, event ordering, event idempotency keys, SAFE-T IDs, support IDs, outbox/DLQ state and evidence references.
- Shadow audit compares decisions without sending writes.

- [ ] **Step 1: Write migration round-trip test**

Fixture export/import must preserve counts and hashes and reject duplicate/inconsistent records.

- [ ] **Step 2: Implement export/import verification**

Do not print secrets or raw protected evidence bodies.

- [ ] **Step 3: Copy a production snapshot into target DB while old writers remain authoritative**

- [ ] **Step 4: Run shadow decisions against all open production cases**

Expected: no unexplained decision discrepancy. Specifically verify all 14 known SAFE-T claims, D+75 eligibility gates, current approved/denied states and financial amounts after deduplication.

- [ ] **Step 5: Correct discrepancies via TDD and rerun until zero unexplained differences**

- [ ] **Step 6: Commit migration tooling**

### Task 7: Controlled Cutover and Real End-to-End Validation

**Files:** operational configuration plus target repo deployment scripts; no website runtime code removed yet.

**Interfaces:** one writer runtime at a time.

- [ ] **Step 1: Freeze old writers**

Disable the old ShopVivaliz Amazon Returns Seller Central/Gmail writers while preserving read-only access for final comparison. Verify no `PROCESSING` write jobs remain stranded.

- [ ] **Step 2: Perform final incremental DB migration**

Verify source/target case, event, outbox and DLQ counts/hashes and selected critical records.

- [ ] **Step 3: Switch Fred-Win read worker endpoint**

Change status endpoint to `https://returns.shopvivaliz.com.br/api/status-bridge`; verify heartbeat and a bounded real read batch with no orphaned `PROCESSING` jobs.

- [ ] **Step 4: Enable SAFE-T submit channel only after eligibility validation**

Pilot with exactly one real case that is currently eligible, unreceived, financially confirmed, D+75 reached and has no existing SAFE-T. Require external claim ID read-back and subsequent status read before enabling general submit.

- [ ] **Step 5: Enable SAFE-T appeal channel**

Pilot one real first-denial case that is still appealable and has not already been appealed. Require state transition to `APPEAL_SUBMITTED` plus later status read.

- [ ] **Step 6: Enable detailed review email channel**

Pilot one real appeal-denied case. Require exactly one Gmail send, persisted Gmail message/thread ID, no duplicate on replay, and successful inbound correlation.

- [ ] **Step 7: Validate email-response decision path**

When a real reply exists, prove it is analyzed before any next write. If no reply exists during this session, validate the production ingestion path with already-existing historical replies plus deterministic fixtures; leave condition monitoring active.

- [ ] **Step 8: Enable Seller Support channel only for a case where analyzer selects it**

Require existing-case search/read-back and one active support case per reason scope.

- [ ] **Step 9: Reconcile approved cases via Finances**

Recheck the five known approved SAFE-T claims and any newly approved cases. Verify `RECOVERED` only on observed net credit and keep partial/zero credit as `CREDIT_PENDING`.

- [ ] **Step 10: Run full production health/audit**

Expected health gates: no eligible case without required treatment; no denied appeal missing email review; no unclassified reply silently written; no duplicate actions; no stale processing leases; policy rows at D+75; financial duplicate bug absent.

### Task 8: Remove Website Runtime Coupling and Final Verification

**Files in `site-shopvivaliz`:**
- Remove: `api/amazon-returns/`
- Remove: `includes/amazon-returns/`
- Remove: `workers/amazon-returns/`
- Remove: `scripts/amazon-returns/`
- Remove: `admin/amazon-returns/`
- Remove: Amazon Returns systemd installer/unit hooks
- Modify: `.github/workflows/*` to remove Amazon Returns deploy/test hooks
- Modify: docs/policy references that claim the subsystem lives in the website repo

**Interfaces:** website no longer runs or deploys Amazon Returns.

- [ ] **Step 1: Write website isolation regression test**

Fail if production website code contains Amazon Returns runtime endpoints, service restart hooks or deploy ownership.

- [ ] **Step 2: Remove website runtime subsystem only after target production acceptance criteria pass**

Keep migration history/spec docs as appropriate, but no executable Amazon Returns runtime remains.

- [ ] **Step 3: Run website QA and target application CI**

Expected: both repos green independently.

- [ ] **Step 4: Verify production service/process ownership**

Only `amazon-returns-safet.service` owns the subsystem; website deploy cannot restart it; target deploy cannot restart website services.

- [ ] **Step 5: Verify fresh real state**

Query target DB and external read channels to confirm current cases, outbox, Gmail/SP-API/Seller Central health and reconciliation after cutover.

- [ ] **Step 6: Commit/merge cleanup PRs and record cutover evidence**

Migration is complete only after fresh verification proves every acceptance criterion in the approved spec.
