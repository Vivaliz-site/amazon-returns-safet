# SAFE-T D+45 — staged atomic grant-consumption ledger

Status: **IMPLEMENTED CODE / NO PRODUCTION TABLE / NO PROVIDER WRITES**.
This work removes one specific certification gap. It is not an operator
activation, lease certificate, proof of Seller Central eligibility, or approval
to alter the global kill switch.

## Invariants

- An InnoDB table `amazon_return_scoped_grant_consumptions` has a
  `UNIQUE(tenant_id, amazon_connection_id, grant_number)`. It records
  only approved grants #3556/#3673 for tenant/connection 1/1.
- A successful `reserve()` inserts **once** and returns the auto-increment
  ID as a strictly monotonic fencing **candidate**, not a service-worker
  ownership proof. All duplicate attempts return null.
- `consume()` changes `RESERVED` to `CONSUMED` with exact fencing,
  scope and status compare-and-swap. A second consumption fails.
- `quarantine()` changes `RESERVED` or `CONSUMED` to terminal
  `QUARANTINED`. Neither quarantined nor consumed grants can be reserved
  again; there is no silent restart or automatic retry after uncertain
  Amazon submission.
- Under a crash, the persisted unique row survives process restart. A
  recovery operator must reconcile Seller Central before any new grant
  could be considered; do not delete/recycle the ledger row.

## Deployment

1. Back up the live schema and verify a disposable restore before a
   controlled DDL migration.
2. Review and apply `deploy/sql/20261107-scoped-safe-t-grant-consumption.sql`
   through the approved DBA/migration path with an exclusive schema lease.
   This DDL is staged; the deploy timer does **not** execute it.
3. Install the production adapter mapping `reserve()` and `consume()` to
   the existing service-owned MySQL connection. Do not call `reserve()`
   before owner approval and case/provider preflight have passed.
4. Implement a **separate** lease manager that fences all existing writers.
   The ledger's auto-increment token is NOT enough to fence a different
   worker or to certify the required single writer.
5. Certify crash/reboot recovery, restricted principal, owner GitHub read
   token, grant expiry, official Seller Central absence/eligibility, SP-API,
   independent Finances and warehouse, accepted claim ID readback and
   automatic kill-switch restoration before enabling any write.

Tests: `php tests/scoped-safe-t-grant-consumption-test.php` uses a
disposable **in-memory SQLite** fixture to verify one-use concurrency
invariants, strict scope, compare-and-swap, stale fencing and restart
idempotency; SQL DDL is additionally inspected for InnoDB and unique index.
This is a code contract test, not a MySQL integration/production certificate.

The global kill switch stays ON. All seven write flags stay OFF.
Never resubmit case #8803, which already has an external claim.
Case #42471 belongs only to approved grant #3673.

Verdict: **ONE-USE COMPONENT READY, EXECUTOR STILL NOT CERTIFIED.**
