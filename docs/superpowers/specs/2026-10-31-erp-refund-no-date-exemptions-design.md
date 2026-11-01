# Amazon ERP Sales Returns — no age exemption

**Date:** 2026-10-31
**Scope:** `Vivaliz-site/amazon-returns-safet`
**Owner decision:** ERP `Devolucoes de venda` must never be waived solely because the Amazon customer refund is old. This supersedes the ERP 30-day inclusion rule of 2026-09-16 and the earlier 90-day historical exclusion. The SAFE-T/claims timetable remains independent.

## Invariants
- Every order with a **confirmed refunded item quantity** remains a candidate for ERP sales-return reconciliation regardless of refund date.
- An age-ignored persisted workflow must be reactivated and re-evaluated; no manual SQL resets. Preserve the original order, case and refund provenance. Preserve original ERP return ID and return NF links: reactivate to RETURN_CREATED_WAITING_INVOICE or RETURN_INVOICE_EXISTS when appropriate, never mark an existing external return as a new PENDING creation.
- Do not silently infer a refunded quantity from refund amount or issue/create a fiscal NF automatically. When refund amount and date are present but item quantity is zero, expose an explicit degraded health blocker and count pending official Amazon evidence.
- A completed return invoice (with matched quantity) must remain idempotent and must not spawn duplicate returns. New refunded quantities after that invoice must be re-evaluated; if an automatic amendment is not verified, surface a blocker instead of silently skipping the extra units.
- Explicit `IGNORED_BY_USER` status remains an independent operator decision, not a date exemption.
- Creation still requires original-sale/NF matching, existing-return/NF preflight, scoped write authorization and external readback. Failure/uncertainty must remain visible and retry-safe.
- Health and operator views must count historically ignored unfinished workflows as incomplete rather than declaring green.
- Do not enable Amazon, Olist or Seller Support writes merely by changing this rule; external write gates and the production canary remain mandatory.

## Migration and validation
The existing 76 date-ignored workflows (11 with known ERP return ID, 65 without) are to re-enter the idempotent reconciliation pipeline after merge/deploy. The 15 separate refund-dated cases with quantity zero need Amazon item-level evidence before any creation. Validate with TDD, full PHP/JS and governance suites, GitHub PR checks, official immutable auto-deploy and production source/runtime readback.
