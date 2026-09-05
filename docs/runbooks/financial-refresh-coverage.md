# Financial refresh coverage and production acceptance evidence

## Scope
Keep D+75, external write flags, and protected credentials unchanged.
No new HTTP endpoint or authentication route is introduced.

## Fair refresh and bootstrap
Use a tenant/connection-scoped keyset cursor over open orders and all orders with expected reimbursement, including recovered cases that can later reverse.
Fetch one extra identifier to detect remaining pages. Each batch is bounded to 25 orders and retains the existing API throttle.
A fresh deployment scans all pages before permitting credit projection. Remaining initial pages run in following daemon cycles, not after a 30-minute wait.
A failed complete cycle waits for the normal cadence; it cannot spin or declare initial acceptance.
Run SP-API before financial reconciliation; a partial/failed refresh cannot authorize projection in that cycle.
A successful initial scan is recorded in the existing scoped cursor store. Normal ongoing rotation includes previously closed financial cases.

## Private journal audit
Scheduler logs an explicit allowlist of case metadata, proposed action/reason and event-type counts.
Financial logs show case ID, current/calculated state, expected/credited/outstanding amounts, source counts and whether the update was applied.
No customer fields, message bodies, tokens, secrets or arbitrary payloads are copied.
After a successful full refresh, a RECOVERED case without supporting transactions is reopened; ordinary cases with no financial evidence remain unchanged.
Audit output belongs to the private daemon journal, not the public health API.

## Tests
financial-refresh-coverage-test: 25+14 pagination, tenant isolation, wrap, retry fairness, valid empty cursor.
financial-initial-scan-test: scan completion, partial/failure gating, task ordering, empty dataset and retry cadence.
runtime-case-audit-test: allowlisted audit, no payload leakage, unsupported recovered-case revalidation.
All 46 PHP test files, SQL audit 61 files and PHP lint passed before PR creation.

## Production acceptance
Require initial_scan_complete=true, no API failures, complete case-level review and zero PROCESSING before enabling any write channel.
A passing test or a SAFE-T approval alone is not proof of a real reconciled payment.
