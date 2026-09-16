# Amazon Returns Global Scope Design — 2026-09-16

## Goal
Certify and, where needed, repair the complete Amazon Returns / SAFE-T production system using real end-to-end evidence, not code/tests alone.

## Authoritative constraints
This design is subordinate to `AGENTS.md`, `AUDIT_POLICY.md`, `AUDIT_EXTREMA.md`, `AI-TO-CLI-PROTOCOL.md`, `docs/REGRAS-DE-ENTREGA.md`, `docs/MEMORIA-DO-PROJETO.md`, `docs/quality/EXTREME_AUDIT_PROTOCOL.md`, `docs/quality/AUDIT_RUNTIME_PARITY_V1.md`, and `docs/quality/AUDIT_OVERLAY.md`.

The operational SAFE-T opening trigger for ShopVivaliz remains D+45 from the Amazon-issued customer refund, subject to live Amazon eligibility, seller physical receipt, actual reconciled reimbursement, and the damaged/discrepant-return manual-first-opening rule already documented in `AGENTS.md`.

## Scope
1. Integrate and production-prove the bounded Gmail incremental catch-up implementation, including monotonic checkpointing, 25/25 bounded batches, safe 404 recovery, rate-limit retry, write gates while evidence is incomplete, restart persistence, final `has_more=false`, and return to 12-hour cadence.
2. Prove ERP/Tiny/Olist sales-return creation for every applicable active refund: order -> ERP sale -> sales invoice -> ERP return -> return invoice, with idempotency, external readback, and no invented invoice for legacy ERP cases.
3. Prove the intake/consultation UI by Amazon order, sales invoice, return invoice, SAFE-T/TBR and existing useful identifiers; missing-local-order lookup must sync Amazon immediately before returning not-found; physical receipt must validate quantity, be idempotent, attach evidence, and immediately influence SAFE-T/ERP flow.
4. Prove the operator cockpit/review UX, server-side filters/pagination, current deadlines, clear human language, deterministic auto-resolution, and correct session restore on desktop/mobile.
5. Prove the SAFE-T lifecycle, Seller Central/Seller Support writes and readbacks, financial reconciliation, partial-credit handling, existing-thread/case reuse, and `RECOVERED` only after real positive financial reconciliation.
6. Prove autonomy with Fred-Win and KOCEPSV not required for normal operation, browser automation on demand only, functional health degradation for unavailable mandatory capabilities, no stale queues/dead letters/PROCESSING, and no excessive polling.
7. Audit every active production case under the current <=90-day scope, reconstructing evidence across Amazon, Gmail, SP-API, Returns, Finances, SAFE-T, Seller Support, ERP, outbox, decisions, deadlines and financial result.
8. Finish with contradictory re-audit and a requirement matrix with `COMPROVADO`, `INFERIDO`, `NÃO VALIDADO`, `BLOQUEADO`, or `OUT_OF_SCOPE`. Material `NÃO VALIDADO` blocks completion.

## Delivery architecture
Use one isolated worktree based on current `origin/main`. Reuse existing validated subsystem designs/plans instead of duplicating them: `docs/superpowers/plans/2026-09-12-erp-sales-return.md`, `docs/superpowers/plans/2026-09-15-extreme-ui-operational-improvements.md`, `docs/superpowers/plans/2026-09-16-real-e2e-audit-governance.md`, and the Gmail catch-up design/plan from `feat/gmail-incremental-catchup-20260916` after reconciliation with current `main`.

Every defect follows root-cause investigation and TDD: reproduce -> RED -> minimal fix -> GREEN -> focused regression -> full suite. Every external write follows gate check -> canary -> destination readback -> reconciliation. Deploy only via the official auto-deploy gate. Preserve concurrent work.

## Evidence model
For each critical flow record release SHA, entity/case reference, entry point, before/after state, external target response/readback, durable persistence/reconciliation, and contradictory retry/idempotency evidence. Process liveness, HTTP 200, CI green, queue-empty, or a log message alone never certifies the flow.
