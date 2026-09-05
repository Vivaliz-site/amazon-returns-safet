# Evidence-based per-order routes

## Contract
Thresholds and operational actions are separate. The router consumes the policy decision already selected by the tenant; it does not grant Amazon eligibility. Any tenant operational D45 opening and Amazon published45/60 periods must remain distinct and explicit.
A new claim needs verified return-to-seller transport evidence, confirmed financial exposure, the configured eligibility gate and Amazon's own preflight. Classic FBA, lost/refused/undeliverable shipment, carrier damage and warehouse discrepancies use different routes.

## Reads before actions
CHECK_FINANCES requests the existing SP-API/reconciliation cycle, not a fictitious payment. Successful API reads create case-scoped FINANCIAL_REFRESH_CONFIRMED receipts; current reconciliation results create FINANCIAL_RECONCILIATION_CHECKED. Both are metadata with financial_truth=false.
A receipt from another case, a stale/future/missing timestamp or an incomplete read is not accepted. Unknown financial transactions prevent automatic overdue escalation.
WAIT_PROACTIVE_CREDIT preserves an explicit promise until the end of a date-only Brazilian deadline. An instruction to reopen ON a date is a different contract and must use the owner-approved dated-resumption workflow instead of silently changing this deadline meaning.

## Existing work
Previously sent review email suppresses duplicates for the same claim. UI status polling must not erase completed email/support stages or real financial recovery.
A first/continuing appeal requires an explicit current official deadline; missing or expired windows route to human review instead of inventing business-day holidays.
Carrier delivery never proves physical receipt. A marked delivery may create a separate7-calendar-day evidence window; unresolved physical damage does not become a non-return claim.

## Production prerequisites
Keep writes OFF until the exact effective policy/operational-start distinction is reconciled with any concurrent deployment branch. Do not merge this branch's prior matrix history over a separately approved operational-policy change.
Require real case audit, no stuck PROCESSING, and real financial evidence before one-channel canaries. Current approved source changes passed56 PHP test files with SQL isolation audit65 files; test counts are development evidence, not production acceptance.
