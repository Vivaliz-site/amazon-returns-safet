# API-first buyer refund discovery (P0)

This command reconciles *customer* refunds already visible in Amazon SP-API Finances but absent from the local Amazon Returns case table. It does not file a SAFE-T claim, open seller support, relax a write gate, or prove that the seller is entitled to reimbursement.

## Execution contract

- Read `AGENTS.md` and the canonical ShopVivaliz rules before executing.
- Use the current immutable Amazon Returns release as `AMAZON_RETURNS_RELEASE_ROOT`, with the configured private `AMAZON_RETURNS_ENV_FILE`. Never edit a `current` release in place.
- Run `php scripts/amazon-returns/refund-backfill-api.php --days=90` first. Its default mode is read-only; it scans the most recent 90 days in at most 15-day windows using **GET Finances listTransactions**, validates pagination, requires `RELEASED`/`DEFERRED_RELEASED` refund transactions and compares order identities within the current tenant/connection.
- The newest `postedBefore` is **UTC now minus three minutes**, to avoid Amazon InvalidInput for too-recent boundaries. The next authorized run will observe the last three minutes.
- Read the counts and preflight candidate hash. To ingest only the identical, scoped set, run `php scripts/amazon-returns/refund-backfill-api.php --days=90 --apply --expected-hash=<sha256 from dry-run>`. Any candidate drift fails closed; redo preflight, do not bypass.
- Apply is capped at 75 missing orders, serialized by a named MySQL lock. Each candidate is re-checked for absence, read through **SP-API Orders + Finances**, required to have exactly one order item and a confirmed financial refund, and persisted in a DB transaction via the established `SvAmazonSpApiEventSink`. Per-order failures roll back. Multi-item orders remain for exact item-level review; do not guess attribution.
- This is a recovery CLI, not an automatically scheduled service. Scheduling must be integrated and tested separately using the canonical single-writer runtime; do not create a second competing worker or unmanaged cron.
- After applying, verify counts, case/event linkage, policy projection, financial evidence, the original missing-order gap, and actual Seller Central eligibility for each case. A local refund event is not an external SAFE-T claim, nor seller reimbursement.

## Emergency evidence — 5 November 2026

A production dry-run for tenant 1/connection 1 returned six windows, six pages, 84 released refund rows, 59 distinct refunded orders, 14 orders absent from the case table. The hash-pinned apply inserted 13 single-item order cases, skipped one multi-item order without guessing its item, and reported zero failures. An independent MySQL query confirmed 368 cases in total and 13 newly created records. Their policies require review pending refund initiator/eligibility verification. No external SAFE-T/support writes were attempted.


## Recurring single-writer discovery (after PR deployment)

The existing `amazon-returns-safet.service` runs `SvAmazonRefundDiscovery::runDue` from its `sp_api` rotation. There is **no new timer, daemon, cron, login, or external-write channel**. The per-tenant/connection cursor `SP_API/buyer_refunds_daily` records one completed scan per UTC day, with a 30-minute retry backoff on partial or failed batches.

The scan uses the same Finances 2024 API, the last 90 days in at most 15-day windows, a three-minute Amazon safety margin, pagination/cursor guards, and released-only refund classification. It compares every discovered order to current tenant/connection cases and uses the same MySQL mutex as the emergency CLI. Each daily application is capped at **25 single-item orders**; any excess is processed on subsequent regular `sp_api` cycles, without a competing worker. A multi-item or unconfirmed refund cannot be assigned a case by guesswork.

Observability: the `sp_api` task result contains `refund_discovery.status`, `refund_orders`, `missing_orders`, `inserted_cases`, `skipped_ambiguous`, `skipped_not_released`, `review_required` and `failed`. Read the scoped cursor metadata and reconcile the case/event projection. A daily scan that finished API processing but skipped any multi-item/uncertain refunded order reports **`REVIEW_REQUIRED` rather than `OK`** and counts the unresolved orders; its daily cursor still advances to prevent repeated API calls. During `SKIPPED_TODAY`, `last_scan_status` and `review_required` expose the outstanding item-level review, including legacy cursor metadata previously marked `OK` despite `skipped_ambiguous>0`. These markers are review evidence, not Amazon-initiated confirmation. A successful ingestion is **not** proof of SAFE-T eligibility, a submitted external claim, seller credit, or health of the Gmail connector.

Regression: `php tests/refund-discovery-daily-test.php` checks pagination, time margin, released-only filtering and cursor fail-closed handling. `php tests/refund-discovery-verdict-test.php` checks RED/GREEN status for ambiguous/unreleased orders, bounded batching, scan failures, and legacy cursor readback. Production readiness additionally requires a published release SHA and readback of the daemon's real `sp_api` task plus the case counts.

## Regression

Run `bash tests/refund-backfill-api-guard-test.sh`. This is a local guard test, **not** live-SP-API or Seller Central certification. The documented production dry-run, apply and DB readback are operational evidence for the one-time backfill, not permanent scheduler coverage.

Related incident: https://github.com/Vivaliz-site/amazon-returns-safet/issues/532


## Continuous SAFE-T / refund reconciliation (owner rule, 2026-11-08)

The already-running Amazon Returns daemon performs Finances refund discovery and
schedules **read-only Seller Central claim lookups** using the EXISTING
SAFE_T_DISCOVERY outbox and browser read worker. It never creates a claim
as part of scanning.

1. Read the most recent tenant-and-connection scoped **Seller Central-confirmed**
   SAFE-T ID in the database. Only matching external claim IDs in
   SAFE_T_ID_DISCOVERED or confirmed SELLER_CENTRAL_ACTION_RESULT events
   qualify; a Submit click, queued job or a mere case update does not qualify.
   confirmed_at is the **time the claim was observed in the system**, not an
   independently attested Amazon creation timestamp.
2. Retrieve released/deferred-released customer refunds from SP-API Finances,
   with existing 90-day rolling coverage, pagination and a three-minute UTC
   boundary margin. Prioritize refunds with postedDate **later** than the
   verified observation, plus refunds whose date cannot be verified. If no
   confirmed claim exists, treat all retrieved refunds as reconciliation
   candidates.
3. Retain the **full lookback**, including older unlinked refunds, as a
   completeness sweep. A newer claim never proves older cases are resolved.
   Reconciliation for older already-imported cases continues through the
   existing StatusBridgeService::ensureJobs() keyset scan.
4. For each single-item order, check whether the claim is already stored;
   otherwise schedule an idempotent read-only SAFE_T_DISCOVERY using the same
   daily key and tenant/connection lease as the established reader. Multiple
   item identities, absent local order, and unverified refund dates require
   further reconciliation; never guess an item or copy a claim across items.
5. The Seller Central worker matches by order and verifies the claim detail.
   A verified positive readback links the external claim ID transactionally;
   an empty search, UI_DRIFT, auth problem, or ambiguous submission remains
   **unverified**, never permission to open another SAFE-T. Financial credit
   must be reconciled separately with SP-API Finances before closing a case.
6. Daily refund_discovery.status=OK is **only** a Finances discovery/ingest
   result; it is **not** proof all SAFE-T claims were checked or credited.
   Monitor seller_central_claim_verification_status and actual external
   ID/status readbacks, not OK alone.

No SAFE-T write flag, global kill switch, whitelist, grant, automation, worker
or runner is changed by this reconciliation. D+45 is the internal first
eligibility check. Case-by-case external eligibility and the D+90 internal
cutoff remain enforced by the separate authorized opening path.
