# Task 3 — Gmail incomplete-evidence decision safety

## Scope and provenance

- Requirements: `.superpowers/sdd/2026-09-16-gmail-incremental-catchup/task-3-brief.md` and `docs/superpowers/specs/2026-09-16-gmail-incremental-catchup-design.md`, plus the explicit same-cycle ruling in chat.
- Worktree: `/home/ubuntu/amazon-returns-safet/.worktrees/gmail-incremental-catchup-20260916`.
- Branch: `feat/gmail-incremental-catchup-20260916`.
- Initial HEAD: `2bfc088c07dee5f2b4a2c1b44a83ff818763a11c`.
- Implementation commit: `5edea62d84f56eaf8150a2fc02929b1080b52798` (`fix: gate writes during Gmail catch-up`). This commit appeared concurrently during validation; its four files match the fixed validation snapshot byte-for-byte. It is preserved without rewriting history.
- CLI namespace: `task3-20260916-7c28`; PHP 8.3.33 CLI, Linux, 2026-09-16 UTC.
- Explicit task instructions limit delivery to local commits: no push, merge, deployment, or subagents were initiated by this session. These override broader repository delivery instructions for this task.

## Files

- `includes/amazon-returns/Runtime.php`: adds `gmailCatchupSkipReason()`.
- `workers/amazon-returns/daemon.php`: gates dependent dispatch, prevents the second Gmail pass from bypassing an incomplete first pass, preserves pending state and skips email claims during incomplete ingestion.
- `tests/gmail-incremental-catchup-test.php`: production-profile email claim and GET-only transport assertions for incomplete versus complete batches.
- `tests/decision-safe-order-test.php`: executes the actual daemon loop with instrumented task dispatch and fake cursor persistence, including known-date duplicate Gmail dispatch and pending/failed/completed transitions.
- This report records evidence and limitations.

## RED evidence

Baseline before modifications:

- `php tests/gmail-incremental-catchup-test.php`: OK.
- `php tests/runtime-review-revalidation-test.php`: OK.

Tests were written before implementation. An initial email fixture omitted required write-profile keys and failed its explicit write-enabled precondition; the fixture was corrected before accepting RED evidence.

Against the unchanged implementation:

- `php tests/gmail-incremental-catchup-test.php`: exit 255, `Incomplete Gmail must not claim email outbox; complete Gmail preserves claiming. expected=0 actual=1`.
- `php tests/decision-safe-order-test.php`: exit 255, `Incomplete evidence must gate scheduler`.

Additional regression-first test during implementation:

- Pending catch-up followed by a failed Gmail result without `has_more` failed with `Pending catch-up remains gated between retries and after failed retry.`
- Cause: the failed result replaced the persisted incomplete-state fallback. Correction: resolve the fallback at the `has_more` key, preserving the gate until explicit completion.

## Design choices

`gmailCatchupSkipReason()` returns `GMAIL_CATCHUP_INCOMPLETE` for Gmail email processing, scheduler, Seller Central draining, review operations, and ERP sales returns whenever `has_more === true`. Review operations can send Gmail reminders; ERP sales returns can create external records based on projected refund evidence. Their combined read/write tasks are conservatively skipped rather than split in this task.

`runGmail()` ingests and checkpoints the bounded batch as before, then returns before `claimBatch()` when incomplete. Its successful ingestion status and progress fields remain intact; `reason` explicitly explains why email execution was skipped. No Gmail POST/send or outbox claim occurs in this path. Read-only here means no external Gmail writes; local ingestion/checkpoint persistence remains required.

The daemon emits `SKIPPED` with the same reason for dependent tasks. It does not mark skipped decisions as executed, consume their known deadline, or acknowledge a successful decision/outbox revision. A duplicate Gmail pass from a known-date wake is suppressed after an incomplete first pass, preserving the first result and five-minute retry marker.

A string `gmail_catchup_pending` marker uses the existing tenant/connection-scoped state file. It keeps dependent tasks blocked between Gmail retries and after a failed retry. Explicit `has_more=false` clears the marker and resumes normal ordered processing. Legacy state without this marker retains existing behavior. Task 1/2 batching, cursor, ingestion, and retry semantics were not changed.

SP-API, financial reconciliation, Returns reports, Gmail evidence reads, policy monitoring, and health retain their existing gates. No credentials, write profiles, schema, policies, production flags, or external services were changed.

## Validation evidence

- Fixed-snapshot full PHP suite: **259 passed, 0 failed**. The snapshot's four changed implementation/test files match commit `5edea62d84f56eaf8150a2fc02929b1080b52798` by SHA-256.
- Focal tests: `gmail-incremental-catchup-test`, `decision-safe-order-test`, and `runtime-review-revalidation-test`: GREEN.
- Full-suite coverage includes `known-deadline-wake-test`, `daemon-task-clock-contract-test`, `financial-pipeline-revision-test`, `gmail-evidence-revision-reconciliation-test`, `amazon-returns-tenant-outbox-test`, `recovery-outbox-retry-test`, `email-executor-gates-test`, `email-review-production-evidence-test`, `email-review-sequence-test`, `dated-email-resumption-test`, and remaining Gmail/email tests.
- PHP syntax validation: **368 files, 0 failures** across includes/api/admin/workers/scripts/tests.
- `php scripts/audit-tenant-sql.php`: `tenant_sql_audit=ok files=109`.
- `git diff --check`: clean.
- Implementation code is locally committed; this report is committed separately so it can name the exact implementation SHA without a self-referential commit hash.

## Concurrency and review

A concurrent edit added an additional failed-retry test using a boolean where the harness expected a state array. The test intent was preserved and its argument corrected. One full-suite run reported 258 passes and that harness error.

A subsequent run reported 258 passes and the expected missing-scheduler-gate failure while the daemon was transiently restored to baseline by another writer. A direct read observed the missing gate; the next read observed its restoration. This is not treated as an unexplained flaky pass. A fixed filesystem snapshot was created and tested to avoid concurrent-source interference, with SHA-256 checks linking the four implementation files to the local implementation commit.

No agents were dispatched by this session. Other worktrees were inventoried and left untouched. Final review checked the exact diff, bounded scope, absence of secrets, unchanged Task 1/2 code, and fail-closed behavior for already-known incompleteness.

## Audit boundary, concerns, and remaining evidence

Classification: SAFE local implementation of an additional restriction. Local invariants below are covered; this is not a formal release certification or a declaration of production readiness. The audit policy, extreme protocol, overlay, and runtime-parity instructions were read. Full published-runtime audit and `AUDIT_STATUS.md` certification are outside the explicit Task-3-only/no-deploy scope and have not been claimed.

| Invariant | Source | Evidence | Result |
| --- | --- | --- | --- |
| Incomplete batch cannot claim/send Gmail email | Chat ruling and spec | Production-enabled fake profile, zero claims, GET-only transport | COMPROVADO locally |
| Same cycle cannot execute dependent decisions/writes | Chat ruling | Actual daemon-loop dispatch test, including duplicate Gmail wake | COMPROVADO locally |
| Independent reads continue | Task 3 brief | SP-API/financial/health dispatch assertions | COMPROVADO locally |
| Pending state survives missing/failed retry | Safe continuation | State transition tests | COMPROVADO locally |
| Completion resumes normal order | Spec | Complete-batch and ordered dispatch assertions | COMPROVADO locally |
| Actual deployed cursor progress and external side effects | Rollout acceptance | No deployment or production calls authorized in this task | NÃO VALIDADO |

Remaining concerns:

- Tests isolate the real task loop and real Gmail ingestion with fake persistence/transport; they do not certify bootstrap, live database transactions, a deployed worker, live outbox contents, or actual mail delivery.
- The state marker relies on the existing local scoped state file. Loss of that file is not certified here. Initial Gmail failures before any known incomplete result retain existing semantics.
- Separate remote polling workers are outside the same-daemon-cycle ruling and are not newly gated by this change.
- Known-date wakes can independently force tasks due; their interaction with the five-minute continuation cadence is unchanged and requires rollout/continuation acceptance.
- Real repeated-batch progress, quota recovery, eventual completeness, restored cadence, dead letters, backup/restore, deployed SHA, and production channel acceptance remain NÃO VALIDADO. No APTO or total-audit-coverage claim is made.
