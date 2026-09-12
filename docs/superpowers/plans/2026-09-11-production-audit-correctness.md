# Production Audit and Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate known operational correctness gaps and prove the Amazon Returns / SAFE-T system behaves autonomously, audibly, and consistently in production.

**Architecture:** Preserve the existing evidence-first deterministic pipeline. Fix continuation and observability at the runtime boundary rather than bypassing financial/Seller Central safety gates; then validate outbox, review memory, cockpit health, and the deployed UI against persisted evidence.

**Tech Stack:** PHP 8 runtime/tests, Node.js Seller Central bridge workers, systemd, MySQL-backed tenant persistence, GitHub Actions/auto-deploy.

**Spec:** `docs/superpowers/specs/2026-09-10-autonomy-cockpit-ux-design.md` and `docs/superpowers/specs/2026-09-06-safet-review-cockpit-memory-design.md`

## Global Constraints

- Known deterministic or learned rules must bypass human review.
- External writes retain write-profile, idempotency and read-back gates.
- Financial evidence must be refreshed before reconciliation/decision.
- Preserve tenant + Amazon connection isolation.
- Do not overwrite concurrent agent work; use this isolated worktree only.
- Production validation must include real runtime state and real UI/HTTP behavior, not tests alone.

---

### Task 1: Financial refresh continuation

**Files:** `tests/financial-recheck-throttle-test.php`, `includes/amazon-returns/Runtime.php`
**Interfaces:** Consume `SvAmazonReturnsRuntime::financialRefreshContinuationRequired(array $results): bool`; preserve daemon state reset contract.

- [ ] Add a regression assertion for a continuation cycle where scheduler is absent, SP-API reports `rotation_has_more=true`, and financial reconciliation is skipped with `FINANCIAL_REFRESH_NOT_ACCEPTED`.
- [ ] Run the focused test and verify it fails for the missing continuation behavior.
- [ ] Change only the continuation predicate needed to keep SP-API/financial due until the rotation reaches an accepted reconciliation point.
- [ ] Run focused test, financial refresh tests, then the full PHP suite.
- [ ] Commit the bounded fix.

### Task 2: Operational queue and external-channel audit

**Files:** read-only production state plus tests/code only if a defect is reproduced.

- [ ] Inspect pending/processing/dead outbox by action kind and age.
- [ ] Verify Seller Central bridge ownership/liveness, TOTP independence, and read-back status without depending on Fred-Win or KOCEPSV.
- [ ] Inspect genuinely overdue `SELLER_SUPPORT_OPEN/UPDATE`, SAFE-T and email actions against case evidence and current decisions.
- [ ] For each defect, add a failing focused test before production-code changes.
- [ ] Re-run all affected channel tests and full suite.

### Task 3: Review memory and cockpit correctness

**Files:** review/learned-rule repositories, cockpit health/API/UI only if reproduced defects exist.

- [ ] Compare open reviews to deterministic and learned-rule decisions; stale/redundant reviews must not remain human work.
- [ ] Confirm 2-hour reminder cadence only targets true open reviews.
- [ ] Validate cockpit health separates user action from operational degradation.
- [ ] Validate outstanding amount/status consistency, universal order/NF/TBR search, return intake preview, case messages and plain-language explanations.
- [ ] Add failing tests before each correction and run focused + full suites.
### Task 4: Integration, deploy and production proof

**Files:** repository governance/deploy artifacts; no direct production edits.

- [ ] Run `git diff --check`, all `tests/*.php`, tenant SQL audit, PHP lint, Node syntax checks and Bash syntax checks from `docs/REGRAS-DE-ENTREGA.md`.
- [ ] Review the diff for scope, concurrency safety and secrets.
- [ ] Push branch, open PR, enable auto-merge, and ensure required validation completes; do not leave a pending PR or Action.
- [ ] Verify deployed `.release-sha` equals the merged SHA and service/HTTP health are good.
- [ ] Observe fresh runtime cycles until financial rotation reaches reconciliation without `FINANCIAL_REFRESH_NOT_ACCEPTED` stalling between batches.
- [ ] Perform authenticated real-UI acceptance when a visible browser connector/session is available; verify cockpit, search, intake, review and case detail against real records.
- [ ] Record any external blocker explicitly; otherwise close only with zero known correctness gaps from this audit.

## Audit evidence already established

- Production release is `d1d4b2f78086582605872716cc99f13542dbd790`, merge #172.
- `amazon-returns-safet.service` is active, but service-level `OK` currently masks financial `SKIPPED` cycles.
- Scheduler has requested 55 financial checks while cycles show `FINANCIAL_REFRESH_NOT_ACCEPTED`.
- `SvAmazonFinancialRefresh::canReconcile()` requires a completed SP-API rotation.
- Current continuation logic forgets the pending rotation after the scheduler disappears from the next cycle, while normal SP-API/financial cadence is 43,200 seconds.
- Baseline isolated worktree suite: 217 PHP tests, 0 failures before changes.
