# Authenticated SAFE-T GitHub owner-grant verifier

**Status (07 Nov 2026): verified component / full privileged operator NOT CERTIFIED.**

Module: `scripts/amazon-returns/scoped-safe-t-github-grant-verifier.mjs`.

This read-only component validates the two already-approved owner grants by making
fresh **authenticated official GitHub REST** reads. It is inert without a
separate **read-only** GitHub credential
`SHOPVIVALIZ_OWNER_GRANT_GITHUB_TOKEN`, which must be provisioned only by
the approved secret-delivery mechanism for the dedicated service principal.
Never print, copy or commit the token, and never reuse a token with repository
write permission for verification. If the token is absent or access returns
401/403/404/429/network error, fail closed.

`verifyApprovedOwnerGrant(3556 | 3673)` requires all of:

- PR in `Vivaliz-site/site-shopvivaliz`, authored by `fredmourao-ai`,
  genuinely merged (not merely closed), with valid head and merge SHAs.
- The **latest** submitted human review by `shopvivaliz-human` must still be
  APPROVED and point to the **exact** PR HEAD, before the merge.
- Read `security/owner-approvals/{3556|3673}.json` at the exact **merge**
  commit; pin its Git blob SHA to the source-of-truth owner-reviewed blob.
- Match schema, principal, host, operation, tenant 1, connection 1,
  exact case allowlist, expiry, one-use, sole `SAFE_T_SUBMIT` action,
  and all nine mandatory guards to the immutable owner approval.
- Enforce **exclusive UTC expiry** at verification time.

It never confuses #8803's existing claim with an unsubmitted case. #42471
belongs only to grant #3673. This module does not turn on a write profile or
kill switch and does **not** prove independent Amazon provider eligibility.

**Integration rule:** the trusted production adapter, once independently
reviewed and installed for the dedicated service, must call this verifier
directly at both core preflights. Do not inject a caller-supplied
`verifiedBy` object. The test-only `fetchImpl` dependency exists solely
for synthetic HTTP-response fixtures; production uses built-in TLS-backed
`globalThis.fetch` with a scoped secret.

Test:
`node --test tests/scoped-safe-t-github-grant-verifier.test.mjs`.
Tests are synthetic and do not require a token or visit Seller Central.

**Still missing for certification:** effective dedicated service identity/RBAC,
lease/fencing and one-use durable storage, single-writer isolation using
existing worker, crash/reboot-safe rollback, SP-API/Finances and warehouse
proof by case, authenticated Seller Central eligibility and deduplication
E2E, accepted claim ID readback, and an independently tested release
with kill switch and unrelated six write flags preserved. Until then,
**NO EXTERNAL WRITE**. Record authoritative evidence at issue #532.
