# Gmail Incremental Catch-up Design

**Status:** approved in chat on 2026-09-16

## Goal

Recover the Amazon Returns Gmail incremental cursor without dropping evidence, without hammering Gmail API quota, and without allowing decisions or external writes to rely on incomplete Gmail evidence.

## Production evidence

- Gmail incremental cursor `history_id_v2` has been stale since 2026-09-13 15:06 UTC.
- Read-only production pulls repeatedly fail with `Gmail API HTTP 403 reason=rateLimitExceeded`.
- A full read-only pull over the stale cursor took about 59 seconds and still failed.
- The existing short request backoff is useful for brief quota spikes but cannot drain a multi-day backlog safely.

## Architecture

Replace the all-or-nothing incremental pull with bounded Gmail history batches. Each successful batch is ingested atomically from the application's perspective, then the persisted cursor advances only to a checkpoint proven to cover that batch. If Gmail reports more history, the runtime schedules another catch-up pass shortly instead of waiting 12 hours.

Normal 12-hour cadence resumes automatically once the persisted cursor reaches the current Gmail history position.
## Batch contract

`GmailApi` will expose a bounded incremental read that returns messages plus explicit progress metadata: the checkpoint cursor covered by the returned batch, whether more history remains, and the mailbox's current history id observed at the start of the call.

The batch must use a small fixed history page size and a bounded number of message fetches. A single daemon cycle must never attempt to drain an unbounded backlog.

The existing GET-only 1/2/4-second quota retry remains. If quota remains exhausted, the task fails safely and is retried after five minutes. POST/send remains non-retried.

## Cursor and ingestion semantics

The persisted `GMAIL/history_id_v2` cursor advances only after every message in the returned batch has been normalized and ingested successfully. A failed batch leaves the previous cursor untouched.

Checkpoint advancement must be monotonic. Duplicate message/event ingestion remains protected by existing event/idempotency semantics.

## Decision safety

While Gmail catch-up reports `has_more=true`, Gmail evidence is incomplete. The daemon may continue unrelated health and read-side work, but the scheduler must not execute or enqueue writes whose correctness depends on the incomplete Gmail refresh.

The catch-up continuation itself is due again after five minutes. Once `has_more=false`, the normal scheduler path resumes with complete Gmail evidence and the regular 12-hour Gmail cadence is restored.
## Observability

Operational task metadata will expose catch-up state without message bodies or credentials: batch message count, checkpoint advancement, `has_more`, and retry reason when rate limited. No OAuth token, cookie, email body, subject, recipient, or raw Gmail response is persisted in operational metadata.

## Recovery and rollout

The first deployed revision runs against the existing stale cursor; no cursor reset or data deletion is allowed. Catch-up progresses in bounded passes until current. If a batch cannot complete, the prior cursor remains authoritative.

Deployment follows the repository auto-gate only. Production validation must prove: cursor timestamp advances from the 2026-09-13 position, repeated batches make monotonic progress, eventual `has_more=false`, normal cadence restoration, no dead letters, and no external Gmail writes caused by catch-up.

## Test strategy

Tests cover multi-page history, bounded batches, checkpoint monotonicity, no cursor advance on partial failure, rate-limit continuation, scheduler/write gating while incomplete, completion back to 12-hour cadence, and regression of normal Gmail search/send behavior.