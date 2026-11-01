# Seller Central TOTP private-route recovery (2026-11-01)

## Scope and observed failure

Incident: [Amazon Returns #532](https://github.com/Vivaliz-site/amazon-returns-safet/issues/532).

The production Seller Central login configuration on `marina-a1-4c24g` still references the backend through `10.0.1.38`, its OCI tenancy-private IP. That TCP/22 path is **not routable from Marina's separate tenancy**. The backend's existing Tailscale address `100.66.174.74` is reachable on TCP/22. This does **not** prove the SSH identity or authorization to read a TOTP.

Before recovery, separate checks confirmed:
- Seller Central username/password files and the TOTP SSH client key are provisioned under the protected service identity on Marina, without exposing their values.
- The backend's Amazon-specific TOTP seed exists in a private `0600` file, and its existing generator emits a six-digit code without exposing it.
- The old private-IP identity is the only entry in the TOTP client's pinned `known_hosts`.
- The historical Amazon browser on Marina is disabled. **Current browser policy requires Seller Central browser sessions on `always-free-arm-1787907847-26`**, not Marina or Fred-Win.

## Proposed minimal change (NOT activated by this PR)

The `seller-central-auth.mjs` adapter now optionally accepts `SELLER_CENTRAL_TOTP_HOST_KEY_ALIAS` in addition to the existing `SELLER_CENTRAL_TOTP_HOST`. When explicitly provided it passes **`HostKeyAlias` to OpenSSH while retaining `StrictHostKeyChecking=yes`, `BatchMode=yes`, `IdentitiesOnly=yes` and the existing pinned `UserKnownHostsFile`**. The new option is intentionally off by default. It rejects whitespace, flags, wildcard and path characters.

This enables use of a private routed address while verifying the **same host identity already pinned under the old canonical alias**. It does not trust any new host key, use `ssh-keyscan` as trust, or disable verification. The known host identity must first be corroborated by an independent authorized operator. If the host key or restricted SSH principal cannot be certified, **fail closed** and do not switch the route.

Do NOT set or test a live credential route on Marina solely because the unit tests pass. A separate approval and deployment mechanism must explicitly authorize the exact host/principal and enforce the correct backend-only browser location. The adapter's code change alone is not an approved host route.

## Required owner approval and deployment preflight

**This is a security-sensitive change**. Its draft PR requires personal review and merge by `shopvivaliz-human` per `Vivaliz-site/site-shopvivaliz/docs/knowledge/security-approval-via-pr.md`.

Before enabling the route, the operator must check:

1. Authenticate to both active hosts using the existing, independently authorized routes. Do not contact or recreate the retired VM.
2. Confirm the Tailscale peer resolves to the intended backend with verified SSH host-key identity against the **already pinned** host, without accepting an untrusted new key or modifying `known_hosts` based only on network output.
3. Confirm the TOTP SSH key is authorized **only** for a single OTP generator command with forwarding, agent, shell and PTY prohibited; it must not provide general Ubuntu shell access. If that restricted principal does not exist, prepare a separate security grant for it instead of broadening this PR.
4. Provision/activate a Seller Central browser **only on the canonical backend VM**, in a distinct persistent profile and with an authorized credential broker or protected service store. Do **not** copy browser cookies or the seed, and do not transfer plaintext credentials through logs, commands, GitHub, chat or unapproved endpoints. Existing protected credentials on Marina are not proof of access in backend's browser profile.
5. Acquire the relevant browser/single-writer lease before any login; respect MFA/CAPTCHA, RBAC and provider refusals. The official Amazon browser is the only evidence for eligibility.
6. Run a bounded, auth-only test with result classification `SESSION_REUSED`, `SESSION_REAUTHENTICATED`, `AUTH_REQUIRED` or `HUMAN_CHALLENGE`, without printing credentials, OTPs, cookies, URLs containing tokens or PII. Preserve 7/7 external writes OFF and kill switch ON.
7. On each pending SAFE-T case at owner D+45, check refund, physical receipt, current seller credit, existing claims and **live Amazon eligibility**. Never submit a duplicate or infer claim acceptance from an internal queue.

## Regression and rollback

Tests: `node --test tests/seller-central-auth.test.mjs`. New tests verify secure alias forwarding, rejection of malformed aliases, and preservation of the exact pinned/strict SSH options. The pre-existing no-alias test covers compatibility.

Rollback: unset the optional `SELLER_CENTRAL_TOTP_HOST_KEY_ALIAS` setting or revert this code PR; leave the browser on backend, external writes disabled and existing queues untouched. Do not fall back to unpinned SSH or a retired host. Successful unit tests **do not** certify remote TOTP transport or Amazon login; a real authorized host-key/lease/UI E2E is required.
