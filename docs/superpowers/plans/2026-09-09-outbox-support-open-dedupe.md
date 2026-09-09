# Seller Support Outbox Deduplication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Prevent repeated fresh financial checks from creating multiple pending `SELLER_SUPPORT_OPEN` jobs for the same unresolved FBA recovery episode.

**Architecture:** Keep financial evidence refreshes independent, but derive the Seller Support open idempotency key from the logical recovery episode rather than the transient finance event row id. A closed prior support case starts a new episode and therefore receives a different key. Existing outbox uniqueness then suppresses repeated enqueue attempts.

**Tech Stack:** PHP decision engine, MySQL outbox uniqueness, GitHub Actions.

**Spec:** Existing approved VM-first autonomous Amazon Returns / SAFE-T architecture and current 12-hour business cadence.

## Global Constraints
- Do not execute duplicate external Seller Support writes.
- Do not delete financial evidence.
- Preserve the ability to open a new support case after a prior support case is closed/resolved/cancelled.
- Keep existing write gates, leases and idempotency enforcement.

### Task 1: Stabilize classic FBA support-open idempotency
- [ ] Add a failing regression test showing two equivalent finance refreshes produce the same idempotency key.
- [ ] Verify the test fails against current `main`.
- [ ] Change only the classic FBA support-open key to use a logical support episode key.
- [ ] Verify the new test and the full suite pass.

### Task 2: Production backlog cleanup and drain
- [ ] Keep one valid pending `SELLER_SUPPORT_OPEN` per logical case/episode and suppress duplicate pending rows without contacting Amazon.
- [ ] Trigger the VM Seller Central drain once after deployment.
- [ ] Verify no duplicate external support case is created and the remaining outbox advances.
