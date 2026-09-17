# TDD verification gates

1. RED: the new Seller Support deferred-recovery contract must fail because no recovery method, full execution-stack fingerprint, or daemon trigger exists yet.
2. GREEN: only the minimum scoped recovery and revision wiring may be added.
3. Regression: existing outbox lease/idempotency, Seller Support lookup/pacing, Gmail catch-up gates, and full CI must remain green.
4. Production: deploy only through `amazon-returns-deploy.service` and prove recovered jobs by ACCEPTED/ALREADY_EXISTS plus concrete external IDs/readback.
5. Do not modify production outbox rows manually.