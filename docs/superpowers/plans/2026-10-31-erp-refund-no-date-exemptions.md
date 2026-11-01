# Amazon ERP Refunds without Date Exemptions — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans and test-driven-development. Use independent review where available.

**Goal:** Never discard an ERP sales return for age alone while preserving external write safety.
**Architecture:** Remove only the ERP refund-age filter; reactivate legacy ignored workflows through the scoped repository; expose unfinished legacy cases in ERP health.
**Tech Stack:** PHP 8.x, MySQL, Olist/Tiny browser/API, GitHub Actions.
**Spec:** `docs/superpowers/specs/2026-10-31-erp-refund-no-date-exemptions-design.md`

## Tasks

### Task 1: Remove age filtering with RED/GREEN
- [x] Write failing tests for old and new refund dates, no quantity, user-ignored and legacy ignored workflows; run RED.
- [x] Update `ErpSalesReturnTask::refundedOrders` to reactivate all date-ignored persisted orders without the 30-day shortcut.
- [x] Keep external double preflight, quantity identity and user-ignored guards; run focused PHP GREEN.

### Task 2: Remove false-green for historical workflows
- [x] Assert formerly date-ignored rows are included by `countIncomplete` and `incompleteAuditRows`.
- [x] Implement query changes and run relevant health tests GREEN.

### Task 3: Integrate and validate
- [ ] Run full repository PHP suite, Node tests, tenant SQL audit and mandatory governance checks.
- [ ] Conduct independent code review, protect client/financial data, avoid new external writes.
- [ ] Push, open PR, inspect mandatory checks for exact SHA, merge only when GREEN.
- [ ] Follow official immutable auto-deploy, prove live SHA, health and backlog reactivation.
- [ ] Validate ERP canary and readback under legitimately enabled external gates; never claim external creation solely from an internal row or HTTP 200.
- [ ] Continue corresponding SAFE-T V5 audit with claim eligibility, discovery/read, write gates, UI and financial effect, using existing incident issues.

### SAFE-T V5 monitoring correction (read-only)
- [x] Reproduce with failing regression that closed known claims were excluded from `SAFE_T_READ`.
- [x] Remove the local closure skip in the known-ID read query and protect those reads from terminal outbox sweep.
- [ ] Run complete regression suite and observe the real Amazon bridge source after deploy.
- [ ] Reconcile each live claim status and recover external write capability **only** via legitimate write gates and canary evidence.
