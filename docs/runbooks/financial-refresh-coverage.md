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
All 49 PHP test files, SQL audit 61 files and PHP lint passed after integration hardening.

## Production acceptance
Require initial_scan_complete=true, no API failures, complete case-level review and zero PROCESSING before enabling any write channel.
A passing test or a SAFE-T approval alone is not proof of a real reconciled payment.

## Runtime hardening
Financial schedule planning is fail-closed: a cursor-store exception is reported as a failed gate instead of escaping `runOnce()` or permitting credit projection.
Credit application uses a separate persisted case-ID rotation, capped at 250 cases per financial tick, so large tenants remain bounded without starving later cases. Corrupt or non-advancing IDs fail closed.
The disabled integration keeps its normal `SKIPPED_DISABLED` behavior and does not force financial work.
