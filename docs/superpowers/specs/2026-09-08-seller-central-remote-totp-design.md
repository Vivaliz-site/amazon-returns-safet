# Seller Central Remote TOTP Design

## Context

ShopVivaliz currently depends on a browser bridge for Seller Central operations that have no suitable public API. The owner has decided that API-based routines may run every four hours, while browser/non-API routines only need to run once per day. Seller Central authentication normally uses username/password plus a TOTP second factor, after which the browser session can remain valid for a period.

The current Fred-Win bridge must no longer be a single point of failure. The browser/session and the TOTP seed must not live on the same machine.

## Approved architecture

- `shopvivaliz-free-a1` is the primary Seller Central browser host.
- `always-free-arm-1787907847-26` is the TOTP authenticator host.
- `DESKTOP-KOCEPSV` is a browser fallback and never stores the TOTP seed.
- The browser host keeps the Seller Central browser profile/session and the login credential references needed for normal reauthentication.
- The authenticator host stores only the TOTP seed and exposes only a restricted command that returns the current six-digit code.
- Browser hosts request TOTP over authenticated SSH with a dedicated key, host-key pinning and a forced command. There is no public HTTP TOTP endpoint.
- The seed is never returned to the browser host, committed to Git, placed in a shared `.env`, written to logs, or copied into ordinary application backups.

## Authentication flow

1. Reuse the persistent Seller Central session when it is still authenticated.
2. When the Amazon sign-in page is detected, fill the configured account identifier and password from local credential files on the browser host.
3. If Amazon requests TOTP, invoke the restricted SSH command on the authenticator VM and accept only exactly six decimal digits.
4. Submit the code and optionally select Amazon's remember-device control when presented.
5. Re-open the originally requested Seller Central page and continue only after the authenticated state is verified.
6. CAPTCHA, account recovery, security-key/passkey-only challenges, identity verification or any unknown authentication screen fail closed as `HUMAN_CHALLENGE`/`AUTH_REQUIRED`; no bypass is attempted.

The bridge must never log username, password, TOTP seed or OTP. The OTP is kept only in process memory for the immediate form submission.

## Authenticator host

A small standard-library TOTP command runs under a dedicated restricted OS account. The real seed file is readable only by the restricted command's account through the narrowest practical permissions. The SSH authorized-key entry denies PTY, port forwarding, agent forwarding and X11 forwarding and forces the TOTP command. Requests are rate-limited. Operational logs may record only success/failure metadata and timestamp, never seed or code.

Before real enrollment, the command is validated with a disposable test seed. The disposable seed is then removed and production is left fail-closed with `SEED_NOT_CONFIGURED` until the owner enrolls the authenticator in Amazon.

## Browser host

The Seller Central workers become host-neutral: executable path, profile, CDP URL, worker IDs and authentication credential-file locations are configuration values rather than Fred-Win assumptions. Both read and write workers call the same authentication helper. Existing external-write gates, SAFE-T eligibility checks, idempotency keys, outbox leases and financial reconciliation rules remain unchanged.

The primary Linux browser profile is persistent but browser processes remain on-demand. A daily dispatcher starts the browser/worker, drains eligible browser jobs serially and stops the dedicated browser afterward. KOCEPSV can use the same bridge protocol as a standby but has a separate browser profile and SSH key; it never stores the TOTP seed.

## Cadence

- SP-API, Finances API and Gmail API ingestion/reconciliation: every 4 hours.
- Seller Central browser reads, writes and visual/status reconciliation: once per day.
- Browser execution may run immediately outside the daily cadence only for an explicitly approved manual recovery/canary, not as a resident poller.
- Existing channel gates continue to decide whether any external write is allowed. Cadence changes do not enable a channel.

## Health and failover

Health must distinguish configuration readiness from runtime liveness. It reports the age/host of the last successful Seller Central browser cycle and the TOTP channel readiness without exposing secrets. If the primary browser host cannot run the daily cycle, KOCEPSV may claim the same backend jobs through the existing lease/idempotency controls. A job can have only one successful external effect.

## Security invariants

- No real TOTP seed or OTP in Git, chat, application logs or bridge result payloads.
- No password or account identifier in logs.
- Strict SSH host-key verification; never `StrictHostKeyChecking=no`.
- Dedicated restricted key for each browser host; compromise of one browser host does not reveal the TOTP seed.
- No change to Amazon write gates or business eligibility rules as part of MFA automation.
- Unknown/captcha/recovery challenges remain human-gated.
- Test enrollment uses only a disposable seed and is removed before production enrollment.

## Acceptance criteria

1. Unit tests prove RFC-compatible six-digit TOTP generation with a fake seed and prove malformed seed/output is rejected.
2. Browser-auth tests prove session reuse, username/password stage, TOTP stage and fail-closed challenge classification without contacting Amazon.
3. End-to-end test between the two VMs proves the browser host can receive only a six-digit fake OTP through the restricted SSH channel and cannot retrieve the seed.
4. Secret-scan validation shows no fake/real seed, password or OTP is emitted by logs.
5. Disposable test seed is removed and the authenticator reports `SEED_NOT_CONFIGURED` before owner enrollment.
6. Non-API Seller Central scheduling is daily and API scheduling is every four hours.
7. Existing project test suite, tenant SQL audit and syntax checks pass.
8. Change is reviewed, merged, deployed through auto-gate, exact SHA verified in production, and no new external write channel is enabled.
9. Only after criteria 1-8 are satisfied is the owner asked to add the new authenticator in Amazon. The seed/QR is provisioned directly into the authenticator VM without being pasted into chat.