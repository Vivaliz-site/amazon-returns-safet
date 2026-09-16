# Gmail Incremental Catch-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drain a stale Gmail history backlog in bounded, checkpointed batches without losing evidence, hammering quota, or allowing dependent writes on incomplete evidence.

**Architecture:** Add a bounded incremental history-batch API to `GmailApi`, persist the batch checkpoint only after successful ingestion, and teach Runtime/daemon to continue catch-up every five minutes while `has_more=true`. Scheduler execution is gated while Gmail evidence is incomplete; normal 12-hour cadence resumes automatically after catch-up completes.

**Tech Stack:** PHP 8.1, Gmail REST API, MySQL source cursors, systemd daemon, existing PHP test harness.

**Spec:** `docs/superpowers/specs/2026-09-16-gmail-incremental-catchup-design.md`

## Global Constraints

- Do not reset or delete the existing Gmail cursor.
- Do not persist Gmail message bodies, OAuth credentials, cookies, or raw Gmail responses in operational metadata.
- GET-only quota retry stays bounded; POST/send is never automatically retried.
- Cursor advances only after successful ingestion of the complete returned batch.
- Catch-up cannot trigger external email writes.
- Deployment uses the existing auto-gate only.

---

### Task 1: Bounded Gmail history batch

**Files:**
- Modify: `includes/amazon-returns/GmailApi.php`
- Test: `tests/amazon-returns-gmail-test.php`
- Test: `tests/gmail-api-rate-limit-backoff-test.php`

**Interfaces:**
- Produces: `pullIncrementalBatch(?string $cursor, int $historyPageSize, int $messageLimit): array`
- Return keys: `messages`, `checkpoint_cursor`, `mailbox_history_id`, `has_more`, `recovered_cursor`.
- [ ] **Step 1: Write failing batch tests**

Add deterministic transport fixtures covering: one bounded page with `nextPageToken`, message-limit truncation, final page, and a message-fetch failure. Assert the checkpoint never advances past work not fully fetched.

- [ ] **Step 2: Run focal tests and confirm RED**

Run: `php tests/amazon-returns-gmail-test.php && php tests/gmail-api-rate-limit-backoff-test.php`
Expected: FAIL because `pullIncrementalBatch()` does not exist.

- [ ] **Step 3: Implement minimal bounded batch**

Implement `pullIncrementalBatch()` using one bounded `/history` page per invocation and at most `messageLimit` unique `messages.get` calls. Preserve the existing 404 recovery path and GET quota retry behavior. Return an explicit checkpoint that represents only history successfully covered by this batch.

- [ ] **Step 4: Run focal tests and confirm GREEN**

Run the same two PHP tests. Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -am "feat: add bounded Gmail history batches"`

### Task 2: Checkpointed ingestion and continuation

**Files:**
- Modify: `workers/amazon-returns/daemon.php`
- Modify: `workers/amazon-returns/gmail-ingest.php`
- Modify: `includes/amazon-returns/Runtime.php`
- Test: `tests/amazon-returns-gmail-test.php`
- Create: `tests/gmail-incremental-catchup-test.php`

**Interfaces:**
- Consumes: Task 1 `pullIncrementalBatch()` result.
- Produces: runtime result keys `gmail_catchup`, `has_more`, `checkpoint_advanced`, `messages`, `events`.
- [ ] **Step 1: Write failing catch-up tests**

Cover: successful batch persists checkpoint after ingestion; ingestion failure preserves old cursor; `has_more=true` schedules Gmail again in five minutes; `has_more=false` restores regular cadence; repeated batches advance monotonically.

- [ ] **Step 2: Run test and confirm RED**

Run: `php tests/gmail-incremental-catchup-test.php`
Expected: FAIL because catch-up orchestration is not implemented.

- [ ] **Step 3: Implement checkpointed orchestration**

Use `pullIncrementalBatch()` from `runGmail()`. Ingest returned messages first, then save `history_id_v2` to `checkpoint_cursor`. Return catch-up metadata. When `has_more=true`, adjust runtime state so Gmail becomes due in five minutes rather than 12 hours.

- [ ] **Step 4: Run tests and confirm GREEN**

Run: `php tests/gmail-incremental-catchup-test.php && php tests/amazon-returns-gmail-test.php && php tests/gmail-rate-limit-task-retry-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -am "feat: checkpoint Gmail catch-up progress"`

### Task 3: Decision safety while Gmail is incomplete

**Files:**
- Modify: `includes/amazon-returns/Runtime.php`
- Modify: `workers/amazon-returns/daemon.php`
- Test: `tests/gmail-incremental-catchup-test.php`
- Test: `tests/decision-safe-order-test.php` or the repository's current ordering-contract test.

**Interfaces:**
- Consumes: `gmail` result key `has_more`.
- Produces: scheduler/write gate that prevents decisions depending on incomplete Gmail evidence while allowing unrelated health/read-side work.
- [ ] **Step 1: Write failing safety-gate tests**

Assert that `gmail.has_more=true` prevents scheduler/write execution for the incomplete-evidence cycle, while `has_more=false` permits the normal decision-safe ordering. Assert no email send path is called during catch-up.

- [ ] **Step 2: Run tests and confirm RED**

Run the catch-up and ordering tests. Expected: FAIL because incomplete Gmail evidence does not yet gate scheduler execution.

- [ ] **Step 3: Implement the minimal gate**

Add a Runtime helper that classifies Gmail catch-up completeness and have the daemon skip the scheduler for that cycle with an explicit safe reason. Do not disable SP-API/financial/health tasks that do not depend on Gmail completeness.

- [ ] **Step 4: Run tests and confirm GREEN**

Run the same focal tests. Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -am "fix: gate decisions during Gmail catch-up"`

### Task 4: Observability and revision wake

**Files:**
- Modify: `includes/amazon-returns/Runtime.php`
- Modify: `workers/amazon-returns/daemon.php`
- Test: `tests/gmail-evidence-revision-reconciliation-test.php`
- Test: `tests/gmail-incremental-catchup-test.php`

**Interfaces:**
- Produces sanitized operational metadata: `status`, `has_more`, `messages`, `events`, `checkpoint_advanced`, and safe quota error classification only.

- [ ] **Step 1: Write failing observability/revision tests**

Require a new Gmail client revision for the catch-up contract and verify metadata contains no message content, subject, recipient, OAuth value, cookie, or raw API response.

- [ ] **Step 2: Run tests and confirm RED**

Expected: FAIL on the old revision/metadata contract.
- [ ] **Step 3: Implement sanitized catch-up metadata and revision bump**

Advance the Gmail client revision once for the catch-up protocol. Persist only bounded numeric/boolean progress fields and the existing sanitized error classification.

- [ ] **Step 4: Run tests and confirm GREEN**

Run: `php tests/gmail-evidence-revision-reconciliation-test.php && php tests/gmail-incremental-catchup-test.php`
Expected: PASS.

- [ ] **Step 5: Commit**

`git commit -am "chore: observe Gmail catch-up safely"`

### Task 5: Full verification, review, integration and production proof

**Files:**
- Verify all files changed by Tasks 1-4.
- Update docs only if implementation details materially differ from the approved spec.

- [ ] **Step 1: Run repository gates**

Run every `tests/*.php`, tenant SQL audit, tenant isolation, Node/Python tests, PHP lint, Node/bash syntax and `git diff --check` using the repository's current CI contract.

- [ ] **Step 2: Request independent code review**

Review the exact diff against the current `origin/main`. Fix every valid Critical/Important finding and repeat gates.

- [ ] **Step 3: Rebase/merge current origin/main safely**

Fetch remote state, preserve concurrent work, integrate current main, and rerun the full gate on the exact candidate SHA.

- [ ] **Step 4: Push, PR, CI and merge**

Open a PR, require green checks for the exact head SHA, merge without bypassing repository protections, and confirm the merge SHA.

- [ ] **Step 5: Auto-deploy and validate production**

Use only the existing auto-deploy gate. Confirm `.release-sha`, daemon/health, and observe multiple Gmail catch-up cycles. Success requires the persisted Gmail cursor to advance monotonically from the stale 2026-09-13 position, eventual `has_more=false`, normal 12-hour cadence restoration, no dead letters, and no Gmail external writes attributable to catch-up.