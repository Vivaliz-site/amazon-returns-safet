# Deferred Seller Support recovery

Production proved that the Seller Support worker fix is deployed and healthy, but 13 safe pre-write failures remain PENDING behind backoff timestamps produced by the old implementation.

The recovery must be implemented in the outbox model, not by editing production rows. It may only make immediately available existing PENDING SELLER_SUPPORT_OPEN rows with `UI_DRIFT: SUPPORT_CASE_LOOKUP_UNAVAILABLE` and existing PENDING SELLER_SUPPORT_UPDATE rows with `UI_DRIFT: SUPPORT_REPLY_SEND_MISSING`.

The recovery must preserve status, payload, idempotency key, last_error, and attempt_count. Existing retry reconciliation remains authoritative before any external write.

The outbox execution-stack revision must include TenantOutbox, BridgeService, RemoteBridge, and the Seller Central bridge worker so a deployed worker fix triggers one recovery pass. Failed recovery must not acknowledge the new revision.

TDD order: publish the failing contract first, confirm CI RED for the missing recovery, then add the minimum production code, run focused/full gates, merge, deploy through the root-owned deploy service, and prove the 13 jobs by live readback.