# Seller Central Event-Driven Wake Design

**Date:** 2026-09-10
**Project:** Amazon Returns / SAFE-T
**Repository:** `Vivaliz-site/amazon-returns-safet`

## Context

The production application evaluates business state twice daily, every 12 hours, but a previously known official action date must still execute when it becomes due rather than waiting for the next routine sweep. The decision runtime already supports waking the decision path for due `next_action_at` values. The remaining gap is the Seller Central browser delivery layer: production uses the polling bridge, while `amazon-returns-seller-central-browser.service` is normally started only by the 08:00 and 20:00 timer. A Seller Central write queued between those windows can therefore remain pending until the next browser cycle.

The browser must remain short-lived and on-demand. No permanent Chrome/Chromium process and no five-minute business polling may be introduced. The twice-daily browser timer remains a fallback and routine reconciliation path.

## Goal

Start the existing Seller Central browser cycle immediately when an eligible Seller Central write becomes pending because of a due known action, while preserving all existing eligibility, idempotency, tenant scoping, write gates, read-back checks and financial revalidation. Add bounded automatic recovery for pre-write infrastructure/authentication failures: 30-minute retry spacing, maximum three total event attempts, and no automatic replay after a write has entered an uncertain-result phase.

## Chosen architecture

Use a local file signal plus `systemd.path` to wake the existing browser service. The application writes an atomic, non-secret wake marker under `/home/ubuntu/amazon-returns-deploy/shared/seller-central-wake/`. A new `amazon-returns-seller-central-browser.path` watches for the marker and starts the existing `amazon-returns-seller-central-browser.service` immediately.

The browser service remains a serialized oneshot unit. It runs the same short-lived Chromium lifecycle and existing `--drain` workers, then terminates the browser. The current 08:00/20:00 timer remains enabled as fallback. A separate 30-minute retry timer performs only a local marker check; without an armed retry marker it exits before launching a browser and performs no Amazon, Gmail, SP-API or Seller Central query.

This architecture avoids a resident browser, avoids external polling, and uses systemd as the local wake primitive rather than requiring the PHP daemon to obtain systemd privileges.

## Components and responsibilities

### 1. Application-side wake signal

Add a focused PHP component, `SvAmazonSellerCentralWake`, responsible only for writing an atomic wake marker when the production bridge mode is `polling` and a Seller Central write is actually pending for immediate delivery.

The marker contains only operational metadata needed for the dispatcher, such as schema version, request timestamp, source and attempt number. It must contain no order numbers, case IDs, Amazon messages, tokens, credentials or evidence bodies.

The path is provided through `AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE`. When that variable is absent, the component is a no-op so unit tests and non-production environments do not write host files accidentally.

The scheduler/daemon calls the component only after an outbox row for one of these write kinds is confirmed active: `SAFE_T_SUBMIT`, `SAFE_T_APPEAL`, `SELLER_SUPPORT_OPEN`, `SELLER_SUPPORT_UPDATE`. Gmail-backed actions remain owned by the Gmail channel and do not wake Seller Central.

A duplicate scheduling decision that resolves to an already-completed idempotency key must not create a browser wake. The code must inspect the scoped outbox row and signal only when the row is currently deliverable/pending.

### 2. `systemd.path` immediate dispatch

Add `amazon-returns-seller-central-browser.path` with `PathExists=` for the wake marker and `Unit=amazon-returns-seller-central-browser.service`.

The service stays single-instance by virtue of systemd unit serialization. The dispatch wrapper consumes the existing wake marker at cycle start. If another wake is written while the service is running, the marker remains present and the path unit starts another cycle after the current service exits; this prevents a lost wake without allowing concurrent browsers.

### 3. Event dispatch wrapper and phase marker

Add a small shell wrapper around `run-seller-central-daily.sh` for event-triggered execution. The wrapper records only local operational state and never logs secrets.

The cycle has two safety phases:

- `PRE_WRITE`: browser startup, CDP readiness, authentication/TOTP preflight and read-only preparation. Failure here is known not to have submitted a Seller Central write and can be retried automatically.
- `WRITE_PHASE`: begins immediately before the Seller Central write worker is invoked. Once this phase is entered, a process-level failure is treated conservatively as potentially ambiguous. The event-level retry controller must not automatically replay that cycle merely because the service exited non-zero.

`run-seller-central-daily.sh` will expose the phase transition through a local phase file supplied by environment. The phase file contains no business payload.

Normal job-level results remain governed by the existing bridge contract. In particular, `retry_safe=false` continues to prevent automatic outbox replay; `WRITE_WITHOUT_READBACK_ID`, unresolved submission/read-back uncertainty and equivalent unsafe results are not converted into event retries.

### 4. Retry controller

Automatic event retry applies only when all of the following are true:

1. the browser cycle was started by an event wake rather than the routine 08:00/20:00 timer;
2. the failure occurred during `PRE_WRITE`;
3. the failure is transient infrastructure/authentication, such as browser startup/CDP failure, temporary bridge/network failure before write phase, or recoverable authentication/TOTP preflight failure;
4. no human challenge/CAPTCHA is present; and
5. the total event attempt count is below three.

Three total event attempts means the initial extraordinary attempt plus at most two automatic retries. Retries are spaced at least 30 minutes apart.

After a safe pre-write failure, the wrapper writes `retry-state.json` with the next allowed retry time and next attempt number. `amazon-returns-seller-central-retry.timer` runs every 30 minutes and starts a tiny retry-check service. That service reads only the local retry state. If a retry is due, it recreates the normal wake marker and exits. If there is no retry state, it exits without launching the browser or querying any external system.

On successful completion of the event-triggered browser cycle, retry state is removed. On the third pre-write failure, retry state becomes exhausted and no further automatic event retry is armed; the regular twice-daily browser timer remains available as fallback and the failure is visible in service logs/health evidence.

`HUMAN_CHALLENGE`, CAPTCHA/MFA requiring user intervention, `UI_DRIFT`, and any failure after `WRITE_PHASE` do not arm the 30-minute event retry.

### 5. Production provisioning

Production provisioning creates `/home/ubuntu/amazon-returns-deploy/shared/seller-central-wake` as `root:www-data` mode `0770`. The application service runs as `www-data` and can atomically create the marker. The browser service runs as `ubuntu` with group `www-data` and can consume the marker and maintain retry state.

`provision-production.sh` and `provision-seller-central-browser-host.sh` install the new path/retry units. They enable the path and retry timer only when the same Seller Central browser prerequisites already used for the twice-daily timer are valid. Missing browser credentials/token/auth prerequisites must not cause the application itself to fail deployment.

The existing twice-daily `amazon-returns-seller-central-browser.timer` remains enabled when browser prerequisites pass.

## Data and control flow

1. A known official date becomes due.
2. The PHP daemon refreshes the required evidence, re-evaluates the case and schedules an eligible Seller Central write with the existing deterministic idempotency key.
3. After the outbox row is confirmed pending/active, `SvAmazonSellerCentralWake` atomically creates the wake marker.
4. `amazon-returns-seller-central-browser.path` starts the existing browser service immediately.
5. The dispatch wrapper consumes the marker and records `PRE_WRITE`.
6. The browser starts, authenticates and completes read-only preflight.
7. Immediately before the write worker begins, the phase becomes `WRITE_PHASE`.
8. The existing bridge worker pulls the scoped outbox job, performs pre-write validation, submits only when allowed, reads the external ID back and posts the result.
9. On success, outbox/case state is persisted through the existing bridge service and the browser terminates.
10. If a safe `PRE_WRITE` failure occurs, a retry is armed for 30 minutes later, up to three total event attempts. If failure occurs after `WRITE_PHASE`, no event replay is armed.

## Concurrency and idempotency

The wake marker is level-triggered and idempotent: multiple requests before service start collapse into one browser cycle, which drains the queue. A wake arriving during execution is preserved for a follow-up cycle.

Systemd guarantees a single active browser service instance. The existing outbox tenant/connection scope, leases, deterministic idempotency keys, stale-write superseding and read-back requirements remain authoritative. The wake layer does not change business eligibility or create new write authority.

## Error handling

- Missing wake directory/path: log a safe operational error and keep the outbox pending; do not mark the business action successful.
- Browser/CDP/auth preflight transient failure on event run: arm 30-minute retry if attempt < 3.
- Human challenge/CAPTCHA/MFA requiring user action: no event retry; surface as external block.
- UI drift: no event retry; preserve diagnostic evidence and require code adaptation.
- Failure after write phase begins: no event retry. Existing bridge/outbox result handling and later read-first reconciliation determine the correct next state.
- Duplicate or already-completed outbox action: no new browser wake.
- Retry-state corruption: fail closed, do not launch a browser from the retry checker; log the invalid state.

## Security constraints

Wake and retry files must never contain credentials, tokens, cookies, OTP values, order/customer data or raw Amazon messages. Files live only under the existing shared deploy root with restrictive ownership/modes.

No new endpoint is exposed publicly. No systemd privilege is granted to PHP. The design does not bypass Amazon authentication, CAPTCHA, eligibility, channel gates, idempotency checks or read-back confirmation.

## Testing strategy

Implementation must follow regression-first TDD.

Automated tests must prove:

- a due Seller Central write in polling mode requests a wake;
- Gmail-only writes do not request the Seller Central wake;
- an already-completed idempotent outbox row does not request a wake;
- wake files are written atomically and contain no case/order/evidence payload;
- the `.path` unit watches the exact marker and targets the browser service;
- the retry timer cadence is 30 minutes and the retry checker performs no external query when unarmed;
- total event attempts are capped at three;
- only `PRE_WRITE` transient failures arm retry;
- `WRITE_PHASE`, `retry_safe=false`, `HUMAN_CHALLENGE` and `UI_DRIFT` never arm event replay;
- a wake arriving during a running cycle is not lost;
- browser lifecycle remains short-lived and the 08:00/20:00 timer remains intact;
- provisioning installs/enables the new units only when browser prerequisites are valid.

The complete PHP test suite, tenant SQL audit, PHP syntax checks, Node syntax checks, shell syntax checks and existing browser lifecycle/systemd contract tests must remain green.

## Production acceptance

After merge and auto-gate deployment, acceptance requires the exact deployed SHA and the following functional evidence:

1. `amazon-returns-seller-central-browser.path` is active and watching the production wake marker.
2. The 08:00/20:00 browser timer remains enabled.
3. With a safe non-writing test wake, the path starts exactly one browser service cycle and Chromium terminates afterward.
4. A second marker written while the service is active causes one subsequent serialized cycle rather than a concurrent browser.
5. A simulated `PRE_WRITE` failure arms a retry no sooner than 30 minutes and stops after the third total event attempt.
6. A simulated `WRITE_PHASE` failure does not arm event retry.
7. No extra Chrome/Chromium process remains resident after the cycle.
8. No external write is used merely to test the wake mechanism. A real Seller Central write canary is performed only when an independently eligible, non-duplicate case exists and its channel gate is already authorized.

## Files expected to change

- `includes/amazon-returns/SellerCentralWake.php` — application-side atomic wake primitive.
- `workers/amazon-returns/daemon.php` and/or `Scheduler.php` — request wake only after a deliverable Seller Central outbox action is scheduled.
- `scripts/amazon-returns/run-seller-central-daily.sh` — expose safe phase transition before write worker.
- `scripts/amazon-returns/run-seller-central-event.sh` — consume event marker and manage safe pre-write retry state.
- `scripts/amazon-returns/check-seller-central-retry.sh` — local-only retry-state checker.
- `deploy/systemd/amazon-returns-seller-central-browser.path` — immediate event wake.
- `deploy/systemd/amazon-returns-seller-central-retry.service` — local retry checker.
- `deploy/systemd/amazon-returns-seller-central-retry.timer` — 30-minute conditional retry cadence.
- `deploy/systemd/amazon-returns-seller-central-browser.service` — invoke event-aware wrapper and permit wake-state path.
- `deploy/systemd/amazon-returns-safet.service` — provide the wake-file path to the PHP runtime.
- `scripts/provision-production.sh` and `scripts/provision-seller-central-browser-host.sh` — install, permission and enablement logic.
- focused regression/contract tests under `tests/`.
- `docs/MEMORIA-DO-PROJETO.md` — record the approved event-driven browser wake and retry rule after implementation is accepted.

## Non-goals

This change does not alter D+45 eligibility, SAFE-T business rules, UI wording, Gmail delivery, SP-API cadence, financial reconciliation semantics, Seller Support form selectors, or the browser's 08:00/20:00 routine. It does not introduce a permanent browser or shorten routine external polling below 12 hours.
