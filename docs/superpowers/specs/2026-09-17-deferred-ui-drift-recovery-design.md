# Safe deferred Seller Support recovery design

The bridge is fail-closed, but a corrected bridge can inherit future `available_at` values created by a prior UI_DRIFT. That makes a fixed production worker wait hours before it can prove the fix.

Use the existing revision mechanism as the deployment boundary. Expand `outboxStackRevision()` to fingerprint all code that determines Seller Central outbox execution. When the fingerprint differs from daemon state, run a narrowly scoped recovery before acknowledging the revision.

The SQL recovery is intentionally not generic. It is tenant/connection scoped, PENDING-only, future-only, and limited to the two observed failures that prove no external write completed: lookup unavailable before SELLER_SUPPORT_OPEN, and missing Send control before SELLER_SUPPORT_UPDATE. It only updates `available_at` and `updated_at`.

Attempt counters are preserved. Therefore retries that may have crossed an external boundary still take the conservative reconciliation path. Gmail catch-up write gating remains independent and continues to prevent external writes while evidence is incomplete.