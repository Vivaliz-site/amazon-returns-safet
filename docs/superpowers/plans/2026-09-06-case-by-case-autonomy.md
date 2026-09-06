# SAFE-T Case-by-Case Autonomy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every one of the 40 live Amazon Returns / SAFE-T cases follow the owner-approved deterministic rule when sufficient evidence exists, leave only genuine ambiguities for human review, surface those reviews clearly, and execute the already-defined post-appeal review path safely.

**Architecture:** Preserve the current deterministic engine, tenant-scoped persistence, outbox and bridge. Fix evidence parsing/state projection at their source, retire obsolete open reviews when a later deterministic decision supersedes them, expose review count in the cockpit, then activate only the deterministic detailed-review email channel after a production canary. External actions continue through scheduler/outbox/idempotency/read-back; no direct ad-hoc production writes.

**Tech Stack:** PHP 8.1+, MySQL, systemd, Gmail API, Seller Central bridge, GitHub Actions, vanilla JS admin cockpit.

**Spec:** `AGENTS.md`, `docs/MEMORIA-DO-PROJETO.md`, `docs/REGRAS-DE-ENTREGA.md`, `docs/runbooks/shopvivaliz-d45-operational-policy.md`, `docs/superpowers/specs/2026-09-06-safet-review-cockpit-memory-design.md`

## Global Constraints

- First ShopVivaliz opening is D+45 from confirmed Amazon customer refund; transport status and future reimbursement promise do not create extra opening blockers.
- Full reconciled credit or explicit WAREHOUSE physical receipt blocks a new not-received claim; damaged/discrepant first opening remains manual-only.
- Existing claim denial/appeal lifecycle is deterministic when evidence and official deadline allow it; appeal denial requires exactly one detailed review email.
- Never infer an UNKNOWN refund initiator as Amazon-side without authoritative evidence.
- Amazon requested wait dates and official appeal deadlines must be preserved exactly; internal appeal deadline preempts a longer Amazon wait.
- Only reconciled financial credit closes recovery; partial credit remains CREDIT_PENDING with bounded rechecks.
- No external write bypasses write profile, outbox, bridge, idempotency or read-back.
- Deploy only through the repository auto-gate and verify the exact deployed SHA.

---

### Task 1: Fix deterministic date parsing and denial projection

**Files:**
- Modify: `includes/amazon-returns/AmazonRequestedWait.php`
- Modify: `includes/amazon-returns/ReturnActionRouter.php`
- Modify: `includes/amazon-returns/SafeTStatusService.php`
- Test: `tests/amazon-requested-wait-english-date-test.php`
- Test: `tests/return-action-router-test.php`
- Test: `tests/amazon-returns-safe-t-status-test.php`

**Interfaces:**
- Consumes Seller Central decision text and current SAFE-T state.
- Produces parsed `next_action_at` and authoritative lifecycle state transitions.

- [ ] Add failing tests for day-first English dates such as `12 August 2026` and verify the live case-23 pattern no longer becomes `PROMISED_ACTION_DATE_UNRESOLVED`.
- [ ] Add a failing test proving `CREDIT_PENDING + DENIED + appeal_denied=true` becomes `APPEAL_DENIED_FINAL` unless a later completed escalation stage must be preserved.
- [ ] Run the targeted tests and confirm they fail for the intended reasons.
- [ ] Implement the smallest parser/state changes that make those tests pass.
- [ ] Re-run the targeted tests and existing wait/status regressions.

### Task 2: Stop premature human review before a confirmed refund exists

**Files:**
- Modify: `includes/amazon-returns/SafeTDecisionEngine.php`
- Test: `tests/amazon-returns-safet-decision-test.php`
- Test: `tests/return-routing-integration-test.php`

**Interfaces:**
- Consumes projected case facts and policy result.
- Produces `WAIT` before refund confirmation, instead of `BLOCKED_REVIEW` solely because the placeholder initiator is UNKNOWN.

- [ ] Add failing tests matching live cases 1 and 1263: no confirmed refund/debit, no SAFE-T, UNKNOWN initiator.
- [ ] Assert those cases remain automatic wait/finance intake and do not create a review.
- [ ] Verify the tests fail on current main.
- [ ] Move the no-confirmed-refund guard ahead of the initiator-review branch without weakening the D+45 Amazon-refund gate.
- [ ] Re-run decision/routing tests, including damaged-return and D+45 invariants.

### Task 3: Retire obsolete reviews when evidence becomes deterministic

**Files:**
- Modify: `includes/amazon-returns/ReviewRepository.php`
- Modify: `includes/amazon-returns/DecisionCoordinator.php`
- Test: `tests/decision-coordinator-test.php`
- Test: `tests/review-memory-persistence-test.php`

**Interfaces:**
- Produces a tenant-scoped `resolveOpenForCase()` operation that clears `open_key`, increments version and marks stale review episodes resolved without fabricating a human decision.
- Coordinator invokes it only when the current base decision is no longer HUMAN_REVIEW/BLOCKED_REVIEW.

- [ ] Add failing tests proving a previously open review is removed from the open queue after new evidence yields a deterministic decision.
- [ ] Add a persistence test proving cross-tenant reviews cannot be changed.
- [ ] Verify both fail before implementation.
- [ ] Implement the repository transition and coordinator call atomically.
- [ ] Re-run review-memory/concurrency/coordinator tests.

### Task 4: Make pending reviews impossible to miss in the cockpit

**Files:**
- Modify: `admin/amazon-returns/api/summary.php`
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/index.php`
- Test: `tests/amazon-returns-admin-test.php`
- Test: `tests/cockpit-ui-contract-test.php`

**Interfaces:**
- Summary returns `pending_reviews`.
- Cockpit displays the count on the Reviews tab and a visible pending-review indicator without requiring navigation first.

- [ ] Add failing contract assertions for `pending_reviews` and a visible Reviews count target.
- [ ] Verify the tests fail.
- [ ] Add the summary field and UI rendering with accessible text.
- [ ] Re-run cockpit/admin API/UI tests.

### Task 5: Replay all 40 production cases independently

**Files:**
- Create: `scripts/audit-live-case-decisions.php`
- Test: `tests/runtime-case-audit-test.php`

**Interfaces:**
- Read-only script compares current app decision with owner-rule invariants and emits sanitized per-case verdicts: `MATCH`, `APP_BUG_FIXED`, `GENUINE_REVIEW`, or `WAIT/FINANCE`.
- The script never writes database state or prints message bodies/secrets.

- [ ] Add a failing test for the audit classifier covering appeal-denied, no-refund, future-D45, expired-appeal, and partial-credit scenarios.
- [ ] Implement the read-only classifier using existing projected facts and event metadata.
- [ ] Run against a production snapshot and require all 40 cases to have an explicit verdict with zero unclassified rows.
- [ ] Save full operational evidence outside the repository under `/home/ubuntu/amazon-returns-audit-<timestamp>/case-by-case/`.

### Task 6: Validate and activate deterministic detailed-review email writes

**Files:**
- Modify: `deploy/write-profile.json`
- Modify: `scripts/write-profile-check.php` if required by the versioned profile contract.
- Modify: `scripts/verify-live-tenant-foundation.sh`
- Test: `tests/write-profile-test.php`
- Test: `tests/email-review-production-evidence-test.php`
- Test: `tests/email-executor-gates-test.php`

**Interfaces:**
- Produces a new immutable profile version with `SAFE_T_EMAIL_REVIEW=true`; submit/appeal remain enabled and reply/support stay unchanged until their own real-case acceptance.

- [ ] Add failing profile assertions for the new version and email-review flag.
- [ ] Run Gmail transport/readiness and exact recipient/thread/idempotency preflight without sending a case action.
- [ ] Implement the new profile only after preflight is green.
- [ ] Before broad processing, constrain the first scheduler cycle to one eligible case using the existing case-scoped scheduler/outbox path; verify Gmail sent/read-back and stored event exactly once.
- [ ] Remove the canary constraint, process the remaining deterministic appeal-denied cases, and verify no duplicate outbox/event records.

### Task 7: Full verification, integration and production proof

**Files:**
- Update: `docs/MEMORIA-DO-PROJETO.md` only with durable rule clarifications uncovered by the audit; no raw case evidence.

**Interfaces:**
- Produces a merged, deployed SHA and a fresh 40/40 audit with only genuine human reviews left open.

- [ ] Run all PHP tests, tenant SQL audit, PHP lint, Node syntax checks, shell syntax checks and `git diff --check`.
- [ ] Review the diff, commit all task-owned changes, push, open/update PR, and obtain green required checks for the exact head SHA.
- [ ] Merge the validated head and follow `amazon-returns-deploy.timer/service` until the exact merge SHA is active.
- [ ] Verify service health, queues, dead letters, write profile, bridge singleton/read-back and authenticated cockpit behavior.
- [ ] Re-run the read-only 40-case audit in production; require zero false reviews, zero missed deterministic actions and zero unexplained cases before declaring completion.
