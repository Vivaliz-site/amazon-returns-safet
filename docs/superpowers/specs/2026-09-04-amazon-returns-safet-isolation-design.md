# Amazon Returns & SAFE-T — 100% Isolation Design

**Date:** 2026-09-04
**Status:** Approved in principle; pending written-spec review
**Source repository:** `Vivaliz-site/site-shopvivaliz`
**Target repository:** `Vivaliz-site/amazon-returns-safet`
**Target host:** same production VM currently used by ShopVivaliz
**Target admin URL:** `https://returns.shopvivaliz.com.br`

## 1. Goal

Extract the Amazon Returns / SAFE-T recovery subsystem from `site-shopvivaliz` into a fully independent application with its own repository, deployment, database, credentials, service lifecycle, admin UI, APIs, workers, CI/CD and operational state.

After cutover, the ShopVivaliz e-commerce application must have no runtime dependency on Amazon Returns / SAFE-T and must not execute any Amazon Returns code.

## 2. Non-negotiable business rules

1. `Data Devolucao` / physical warehouse receipt is authoritative physical-return truth. If physical return is confirmed, a new non-return SAFE-T is hard-blocked.
2. `Data Reembolso` is financial history only and never proves physical receipt.
3. Current business policy for initial eligibility is D+75 from the configured financial basis.
4. Eligible cases must automatically open SAFE-T when all write gates are satisfied.
5. SAFE-T status must be read automatically from Seller Central.
6. First SAFE-T denial must be appealed in Seller Central when the appeal route is available and facts support it.
7. If the Seller Central appeal is denied, send an individual detailed review request to `Safe-T-Review@amazon.com`.
8. A negative email reply is not automatically terminal and does not automatically trigger another message. The reply must be interpreted in context before deciding the next action.
9. SAFE-T approval is not terminal until the financial credit is observed and reconciled through Amazon Finances.
10. No fabricated facts, evidence, policy, tracking, dates, amounts, identifiers or Amazon statements.
11. Unknown or ambiguous external responses fail closed and require review rather than blind writes.
12. Every external write must be idempotent and must not duplicate SAFE-T claims, appeals, emails or Seller Support cases.

## 3. Repository and runtime boundary

Create private repository `Vivaliz-site/amazon-returns-safet`.

The target repository owns all code currently under or associated with:

- `includes/amazon-returns/`
- `workers/amazon-returns/`
- `scripts/amazon-returns/`
- `api/amazon-returns/`
- `admin/amazon-returns/`
- Amazon Returns-specific systemd units/installers
- Amazon Returns-specific CI tests/workflows
- Amazon Returns operational documentation

The new repository must not include or require ShopVivaliz website application code such as `config/constants.php`, `includes/pdo-database.php`, site admin guards, site deployment symlinks or the ShopVivaliz production pipeline.

## 4. Same VM, isolated deployment

The application remains on the same VM initially, but with an independent filesystem and service boundary.

Recommended paths:

- repository checkout: `/home/ubuntu/amazon-returns-safet`
- deploy root: `/home/ubuntu/amazon-returns-deploy`
- current release: `/home/ubuntu/amazon-returns-deploy/current`
- shared runtime: `/home/ubuntu/amazon-returns-deploy/shared`
- environment: `/home/ubuntu/amazon-returns-deploy/shared/.env`
- evidence: `/home/ubuntu/amazon-returns-deploy/shared/evidence`
- runtime state: `/home/ubuntu/amazon-returns-deploy/shared/runtime-state.json`

Use a dedicated systemd service such as `amazon-returns-safet.service`. It must not use the ShopVivaliz service name, working directory, environment file or restart lifecycle.

The website deploy must never restart, install or modify the Amazon Returns service after cutover, and the Amazon Returns deploy must never restart the website.

## 5. Independent database

Create a dedicated MySQL database and database user for the isolated application, for example:

- database: `amazon_returns_safet`
- application user: dedicated least-privilege MySQL account

The target application owns its schema and migrations. It must not query or mutate ShopVivaliz website tables.

Historical Amazon Returns tables and event history are migrated into the dedicated database preserving primary identifiers, external IDs, event ordering, idempotency keys, financial values and evidence references.

During migration, the source is frozen for writes before the final copy. Exactly one runtime may perform external writes at any point.

## 6. Independent credentials and secrets

The target application's `.env` owns only credentials needed by this system, including:

- Amazon SP-API LWA credentials
- Gmail OAuth credentials used for Amazon Returns mail processing
- Seller Central bridge token
- application database credentials
- application authentication/session secrets

The application must not read `/home/ubuntu/shopvivaliz-deploy/shared/.env` after cutover.

Secrets, OAuth tokens, browser cookies and MFA values must never be logged or copied into evidence.

## 7. Independent admin application

`returns.shopvivaliz.com.br` serves the internal application directly.

The application includes its own authenticated admin area for:

- operational dashboard
- case search and detail
- physical return intake
- evidence view
- SAFE-T timeline
- appeals and email review timeline
- Seller Support timeline
- financial reconciliation
- health gates
- outbox and dead-letter visibility
- audit trail

Authentication must be owned by this application, not by ShopVivaliz admin sessions. The chosen mechanism may reuse an approved identity provider account, but no website session cookie or website database table may be required.

## 8. External API and Windows bridge

The Seller Central Windows workers must move away from `shopvivaliz.com.br/api/amazon-returns/...` and use the isolated domain, for example:

- `https://returns.shopvivaliz.com.br/api/bridge`
- `https://returns.shopvivaliz.com.br/api/status-bridge`

Read and write bridges remain separated so a read-only worker cannot execute Seller Central writes.

The Fred-Win browser profile/session remains an authorized external browser dependency. Authentication cookies remain on Fred-Win and are not copied to the VM.

## 9. Core case lifecycle

Canonical flow:

`ORDER/REFUND OBSERVED`
→ `AWAITING_RETURN`
→ `SAFE_T_ELIGIBLE` at D+75 when all gates pass
→ `SAFE_T_SUBMITTED`
→ automatic Seller Central status polling

If approved:

`SAFE_T_APPROVED`
→ `CREDIT_PENDING`
→ Finances reconciliation
→ `RECOVERED`

If initially denied:

`SAFE_T_DENIED`
→ analyze exact Seller Central rationale
→ `SAFE_T_APPEAL` when appeal is actionable/available
→ `APPEAL_SUBMITTED`

If appeal is denied:

`APPEAL_DENIED_FINAL`
→ `SAFE_T_EMAIL_REVIEW`
→ send one detailed message to `Safe-T-Review@amazon.com`
→ `EMAIL_REVIEW_SENT`
→ wait for reply in the same workflow

## 10. Email reply interpretation

Responses related to detailed SAFE-T review are first normalized and classified. Classification is a decision aid; any uncertain classification is fail-closed.

Supported outcomes:

### `APPROVED`
Amazon confirms reimbursement/reversal/approval.

Action: move to `CREDIT_PENDING`; do not close until Finances confirms the economic credit.

### `INFO_REQUESTED`
Amazon asks for specific evidence or clarification.

Action: construct a response only from evidence already attached to the case or newly verified official/internal evidence. If the requested evidence is unavailable or ambiguous, route to human review.

### `DENIED_ACTIONABLE`
The response rejects the request but leaves a defensible next action: it contains a factual error, contradicts verified case evidence, requests an alternate channel, leaves an active process available, or new material evidence exists.

Action: decision engine chooses `RESPOND_EMAIL`, `OPEN_SUPPORT`, `WAIT` or `HUMAN_REVIEW` based on the actual message and case facts. No automatic repeated template response.

### `DENIED_FINAL`
Amazon explicitly closes the review path and there is no new material fact, no promised future action to await, no available procedural channel, and no financial inconsistency requiring escalation.

Action: candidate for `CLOSED_LOSS`, with terminal reason and evidence. Terminal closure must be auditable.

### `WAIT`
Amazon promises a future reimbursement/review date or another external dependency is pending.

Action: store the promised date and recheck the authoritative source after that date. Do not send unnecessary replies while the promised action window is open.

### `UNKNOWN_AMBIGUOUS`
The system cannot reliably determine the meaning or next action.

Action: no external write; route to human review.

## 11. Email correlation and idempotency

Every outgoing detailed review email is one case-scoped operation with a deterministic idempotency key based on SAFE-T ID and denial decision fingerprint.

The outgoing message includes the order ID and SAFE-T ID in machine-correlatable headers/body/subject without exposing secrets.

The Gmail source cursor must not depend on unread state.

Incoming replies are correlated using thread/message metadata plus SAFE-T/order identifiers. Replaying the same Gmail message cannot create a second domain event.

A retry of an already-accepted Gmail send must not send a duplicate message.

## 12. Seller Support escalation

Seller Support is not an automatic consequence of every email denial.

It is selected only after analyzing the latest email response and case history. A support case may be opened when there is a defensible unresolved issue and Support is an appropriate next channel.

Only one active support case is allowed per SAFE-T/reason scope. New facts update the existing case rather than opening duplicates.

## 13. Seller Central denial parser

The parser must capture the latest Amazon decision/review rationale, not the original claim field `Motivo`.

It must distinguish at minimum:

- original claim reason
- Amazon denial rationale
- Amazon appeal response
- appeal deadline
- explicit no-more-communications language
- proactive reimbursement promise/date
- appeal-out-of-deadline response
- granted/approved status

Unknown UI text must remain `UNKNOWN`; it must never be guessed as denied or approved.

## 14. Financial correctness

Finances remains the financial source of truth.

The system must treat `DEFERRED_RELEASED` and later `RELEASED` representations of the same economic refund/reimbursement lifecycle as one economic event when the available transaction evidence shows they are lifecycle representations rather than separate economic amounts.

Expected reimbursement and at-risk calculations must use deduplicated economic amounts.

Partial credits remain `CREDIT_PENDING`. A later reversal reopens the case. `RECOVERED` requires reconciled net credit meeting the expected economic amount within the configured monetary tolerance.

## 15. Physical receipt

The new application's physical intake UI becomes the authoritative entry point for `Data Devolucao` / warehouse receipt.

Carrier-delivered status is only provisional. Warehouse physical intake supersedes tracking for actual receipt.

After cutover, ShopVivaliz has no Amazon Returns intake endpoint or dashboard.

## 16. Write gates

External write flags remain individually controlled:

- SAFE-T submit
- SAFE-T appeal
- detailed SAFE-T email review
- Seller Support open/update

Production mode alone never enables writes.

Before any new SAFE-T submit:

1. financial exposure is confirmed;
2. refund context is sufficiently classified;
3. physical return remains unreceived for the claimed quantity;
4. D+75 eligibility has been reached;
5. no existing SAFE-T exists for the same recovery event;
6. evidence bundle is sufficient;
7. corresponding write flag is enabled.

Historical existing claims may continue through their post-submission lifecycle even when old source data lacks fields now required for a new claim.

## 17. Outbox, retries and dead letters

All external actions are represented in an outbox with deterministic idempotency keys.

Seller Central reads, Seller Central writes and Gmail writes must be claimed by the correct worker only; one worker class cannot accidentally consume another channel's action.

Stale processing leases are recoverable. Browser/CDP disconnects reject pending requests instead of leaving unresolved promises that abandon processing jobs.

Deadline-critical retries must not silently retry beyond an appeal deadline. Exhausted or unsafe writes go to a dead-letter queue and create an operational alert.

## 18. Observability and daily audit

Health must reflect actual runtime health, not merely credential/config presence.

Daily audit covers at least:

- eligible cases without expected action
- physical-return blocks
- SAFE-T submissions
- SAFE-T current status
- denials lacking appeal treatment
- denied appeals lacking email review
- email replies awaiting decision
- promised future Amazon actions overdue
- approved cases without reconciled credit
- outbox processing/pending/dead-letter state
- worker heartbeat/auth state
- Gmail and SP-API failures
- D+75 policy correctness
- financial deduplication correctness
- duplicate-action detection

The existing 10-day audit request remains valid across the cutover and must point to the new application after it becomes authoritative.

## 19. Migration sequence

1. Finish correctness fixes in an isolated branch/test environment, without enabling production writes.
2. Create the new private repository and copy the subsystem code and tests into the new project structure.
3. Remove all dependencies on ShopVivaliz site bootstrap, DB layer, admin auth, URLs and deployment paths.
4. Add dedicated DB bootstrap/migrations, auth, deployment and service definitions.
5. Stand up `returns.shopvivaliz.com.br` on the same VM with TLS and isolated document root/reverse proxy configuration.
6. Create dedicated database/user and migrate a snapshot of current Amazon Returns data.
7. Run the new application in shadow/read-only mode against production inputs and compare decisions with the current system.
8. Correct all discrepancies before writes are enabled.
9. Freeze old Amazon Returns writers and queues.
10. Perform final incremental data migration and verify counts/hashes/key case records.
11. Switch Fred-Win workers to the isolated API endpoints.
12. Enable one external write channel at a time and verify read-back/idempotency before enabling the next.
13. Observe the system under daily audit.
14. Remove Amazon Returns runtime code, endpoints, admin UI, service installer and pipeline hooks from `site-shopvivaliz` only after the new system is authoritative and verified.

## 20. Cutover acceptance criteria

The migration is complete only when all of the following are demonstrated with fresh evidence:

- target repo owns the entire subsystem;
- target service runs independently on the same VM;
- target DB contains the complete required historical state;
- no target runtime include/query/deploy depends on `site-shopvivaliz`;
- `returns.shopvivaliz.com.br` admin and APIs operate independently;
- Fred-Win reads Seller Central through the new API;
- D+75 policy is active and verified;
- physical return blocks new claims;
- financial lifecycle duplication is corrected;
- first denial produces appeal treatment;
- denied appeal produces exactly one detailed review email;
- detailed-review reply is interpreted before deciding reply/support/close/wait;
- approved recovery remains open until Finances credit reconciliation;
- unknown responses fail closed;
- no duplicate external action is produced during replay/retry;
- old ShopVivaliz Amazon Returns writers are disabled before new writers are enabled;
- after cutover, ShopVivaliz production deploy no longer installs/restarts/runs Amazon Returns;
- full automated test suite and production smoke/health checks pass.

## 21. Rollback

Until final cleanup of the old site code, rollback is operational rather than dual-writer:

- new writers are disabled;
- data snapshot is preserved;
- old code may be redeployed only after confirming new writers are stopped and migrated state is reconciled;
- two writer runtimes are never allowed simultaneously.

Once the old code is removed from `site-shopvivaliz`, rollback uses the isolated repository's previous release/database backup rather than restoring runtime coupling to the website.
