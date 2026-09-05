> Policy correction (2026-09-05): the former global 75-day rule is withdrawn. Use docs/superpowers/specs/2026-09-05-policy-modes-45-60.md; periods and action routes depend on program, order date and evidence.

# Financial credit safety

## Scope and invariant
A SAFE-T approval or support email is not reconciled credit.
This change does not modify policy the applicable 45/60-day matrix, schema, external write flags, or email/support channels.

## Corrections
- Count only released official transactions; preserve the existing trusted internal seller_effect_amount contract.
- Use integer cents, signed reimbursement amounts, expected currency, and one contribution per official transaction ID.
- Treat Finances v0 and v2024 totals as corroborating sources, not additive payments. This deliberately remains conservative when disjoint payments cannot be proven.
- A current held ledger observation must not be overridden by older v0 evidence.
- Select latest observations by ingestion created_at and event ID, not transaction posting date or input order.
- Append changed financial observations without overwriting prior evidence. Predecessor event IDs distinguish A -> B -> A changes; unchanged snapshots stay idempotent.
- Preserve official relatedIdentifierName/relatedIdentifierValue fields as normalized related_identifiers.
- Reopen RECOVERED when authoritative current credit becomes insufficient.

## Tests and rollout
Tests/financial-credit-safety-test.php reproduces duplicate-source/ID, held-status, currency, signed amount and stale-version failures before the fix.
Tests/financial-observation-version-test.php reproduces dropped identifiers and missing observation versions before the fix.
Run all tests/*.php and the tenant SQL audit before deployment.
After deployment, refresh actual financial observations and review all changed case projections before enabling writes.
No test fixture is evidence that a real customer account was miscredited.

## Primary API contract references
https://developer-docs.amazon.com/sp-api/lang-en_US/reference/listtransactions
https://developer-docs.amazon/sp-api/lang-en_US/docs/finances-api-v2024-06-19-reference
https://developer-docs.amazon/sp-api/lang-pt_BR/changelog/update-finances-api-identifies-deferred-releases
