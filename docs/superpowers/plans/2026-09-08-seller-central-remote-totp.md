# Seller Central Remote TOTP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move Seller Central browser authentication to a resilient VM-first architecture where the browser/session and the Amazon TOTP seed live on separate hosts, while changing API cadence to four hours and non-API/browser cadence to daily.

**Architecture:** `shopvivaliz-free-a1` runs the on-demand Seller Central browser bridge; `always-free-arm-1787907847-26` is a restricted SSH TOTP signer; `DESKTOP-KOCEPSV` remains a browser fallback without the seed. A reusable Node authentication module handles username/password/TOTP screens and fails closed on CAPTCHA or unknown challenges. All existing write gates, eligibility checks and idempotency remain unchanged.

**Tech Stack:** Node.js ESM + CDP, PHP application/tests, Bash/systemd provisioning, Python 3 standard library for TOTP generation, OpenSSH forced commands, GitHub Actions/auto-gate.

**Spec:** `docs/superpowers/specs/2026-09-08-seller-central-remote-totp-design.md`

## Global Constraints

- Never commit, print or copy the real Amazon password, TOTP seed, OTP, cookies, session data, bridge token or SSH private key.
- Never use `StrictHostKeyChecking=no`.
- Keep every existing external-write gate unchanged; this work does not enable a write channel.
- CAPTCHA, recovery, passkey-only or unknown Amazon challenges fail closed and require human review.
- Browser/non-API routines run once per day; SP-API, Finances API and Gmail API routines run every four hours.
- Browser process is on-demand and stopped after the serialized daily drain.
- Real TOTP enrollment happens only after all fake-seed tests pass and the disposable seed is removed.

---

### Task 1: Authentication module contract

**Files:**
- Create: `tests/seller-central-auth.test.mjs`
- Create: `scripts/amazon-returns/seller-central-auth.mjs`

**Interfaces:**
- Produces `classifyAmazonAuthState(state): 'AUTHENTICATED'|'SIGN_IN'|'TOTP'|'HUMAN_CHALLENGE'|'UNKNOWN'`.
- Produces `parseTotpOutput(stdout): string` returning exactly six digits or throwing.
- Produces `requestRemoteTotp(options): Promise<string>` using an injected process runner for tests and OpenSSH in production.
- Produces `ensureSellerCentralAuthenticated(cdp, options): Promise<{status:string, reason:string}>`.

- [ ] **Step 1: Write the failing Node tests**

Test state classification for normal Seller Central, sign-in, TOTP, CAPTCHA/recovery and unknown challenge text. Test that `parseTotpOutput('123456\n')` returns `123456`, while seeds, extra text, five/seven digits and multiline responses throw. Test the remote request command contains `BatchMode=yes`, `IdentitiesOnly=yes`, `StrictHostKeyChecking=yes`, a pinned known-hosts file, the dedicated key path, and no shell interpolation.

- [ ] **Step 2: Run the new test and verify RED**

```bash
node --test tests/seller-central-auth.test.mjs
```

Expected: FAIL because `seller-central-auth.mjs` does not exist.

- [ ] **Step 3: Implement the minimal authentication helper**

Use only file-based account/password references and a restricted SSH child process. Selectors must support the normal Amazon fields (`#ap_email`, `#ap_password`, `#signInSubmit`, `#auth-mfa-otpcode`, `#auth-signin-button`) with conservative fallbacks. Never include the field values in thrown errors or logs. Allow at most one complete automated reauthentication attempt per requested page.

- [ ] **Step 4: Run the test and syntax check GREEN**

```bash
node --test tests/seller-central-auth.test.mjs
node --check scripts/amazon-returns/seller-central-auth.mjs
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/seller-central-auth.test.mjs scripts/amazon-returns/seller-central-auth.mjs
git commit -m "feat: add isolated Seller Central authentication helper"
```

### Task 2: Integrate authentication into both Seller Central workers

**Files:**
- Modify: `scripts/amazon-returns/seller-central-bridge-worker.mjs`
- Modify: `scripts/amazon-returns/seller-central-safe-t-read-worker.mjs`
- Modify: `tests/amazon-returns-browser-contract-test.php`
- Modify: `tests/amazon-returns-read-worker.test.mjs`

**Interfaces:**
- Consumes `ensureSellerCentralAuthenticated` from Task 1.
- Produces host-neutral `SELLER_CENTRAL_WORKER_ID`, `SELLER_CENTRAL_STATUS_WORKER_ID`, `SELLER_CENTRAL_BROWSER`, `SELLER_CENTRAL_PROFILE`, and CDP configuration.

- [ ] **Step 1: Add failing contract tests**

Require both workers to import the shared authentication helper, make worker IDs configurable, prefer `SELLER_CENTRAL_BROWSER` while preserving the old Opera variable as backward-compatible fallback, and never contain hard-coded Fred-only credentials in the new auth path.

- [ ] **Step 2: Verify RED**

```bash
php tests/amazon-returns-browser-contract-test.php
node --test tests/amazon-returns-read-worker.test.mjs
```

Expected: FAIL on the new host-neutral/authentication assertions.

- [ ] **Step 3: Integrate the helper minimally**

When a requested Seller Central page resolves to sign-in/TOTP, invoke one authentication attempt, then navigate back to the original target and re-run the existing auth gate. Preserve existing `AUTH_REQUIRED`, `HUMAN_CHALLENGE`, `UI_DRIFT` and external-write result semantics. Do not change any decision or write-gate code.

- [ ] **Step 4: Verify GREEN**

```bash
php tests/amazon-returns-browser-contract-test.php
node --test tests/amazon-returns-read-worker.test.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
```

- [ ] **Step 5: Commit**

```bash
git add scripts/amazon-returns/seller-central-bridge-worker.mjs scripts/amazon-returns/seller-central-safe-t-read-worker.mjs tests/amazon-returns-browser-contract-test.php tests/amazon-returns-read-worker.test.mjs
git commit -m "feat: recover Seller Central sessions with remote TOTP"
```

### Task 3: Encode the four-hour API / daily browser cadence

**Files:**
- Modify: `workers/amazon-returns/daemon.php`
- Modify: `tests/safe-t-browser-cadence-test.php`
- Modify: `tests/production-autonomy-config-test.php`
- Modify: `docs/MEMORIA-DO-PROJETO.md`
- Modify: `docs/runbooks/fredwin-read-worker.md`

**Interfaces:**
- Produces explicit cadence constants/configuration so API source cycles are due every 14,400 seconds and Seller Central browser cycles every 86,400 seconds.

- [ ] **Step 1: Add failing cadence assertions**

Require API cadence `14400` seconds and Seller Central browser cadence `86400` seconds. Require daily browser scheduling not to alter channel enable flags.

- [ ] **Step 2: Verify RED**

```bash
php tests/safe-t-browser-cadence-test.php
php tests/production-autonomy-config-test.php
```

- [ ] **Step 3: Implement configuration and update persistent project memory**

Keep API pulls independent of browser availability. Update the 2026-09-07 six-hour browser note as superseded by the owner's 2026-09-08 daily browser/four-hour API decision.

- [ ] **Step 4: Verify GREEN**

```bash
php tests/safe-t-browser-cadence-test.php
php tests/production-autonomy-config-test.php
```

- [ ] **Step 5: Commit**

```bash
git add workers/amazon-returns/daemon.php tests/safe-t-browser-cadence-test.php tests/production-autonomy-config-test.php docs/MEMORIA-DO-PROJETO.md docs/runbooks/fredwin-read-worker.md
git commit -m "feat: schedule API four-hour and browser daily cycles"
```

### Task 4: Provision a restricted TOTP authenticator host

**Files:**
- Create: `scripts/amazon-returns/totp-current.py`
- Create: `scripts/provision-amazon-totp-authenticator.sh`
- Create: `tests/totp-current.test.py`
- Create: `tests/totp-provision-contract-test.php`

**Interfaces:**
- `totp-current.py --seed-file <path>` prints one six-digit RFC 6238 SHA-1 code and nothing else on success.
- The provisioning script installs a dedicated command/user, seed path, rate limiter and forced-key restrictions without containing any real seed/key.

- [ ] **Step 1: Write RFC-vector and provisioning contract tests first**

Use only published fake RFC 4226/6238 test material. Verify deterministic output for fixed timestamps exposed only by the test CLI flag, reject invalid Base32, reject missing/over-permissive seed files, and assert the provisioning template contains `no-agent-forwarding`, `no-port-forwarding`, `no-pty`, `no-X11-forwarding` plus a forced command.

- [ ] **Step 2: Verify RED**

```bash
python3 -m unittest tests/totp-current.test.py
php tests/totp-provision-contract-test.php
```

- [ ] **Step 3: Implement generator and provisioner**

Use Python standard library `base64`, `hmac`, `hashlib`, `struct`, and `time`. The test-time override must require an explicit test-only flag and must never be used by the forced production command. The provisioner creates no seed by default; enrollment writes the seed later through a root-only stdin/file operation.

- [ ] **Step 4: Verify GREEN and static checks**

```bash
python3 -m unittest tests/totp-current.test.py
php tests/totp-provision-contract-test.php
python3 -m py_compile scripts/amazon-returns/totp-current.py
bash -n scripts/provision-amazon-totp-authenticator.sh
```

- [ ] **Step 5: Commit**

```bash
git add scripts/amazon-returns/totp-current.py scripts/provision-amazon-totp-authenticator.sh tests/totp-current.test.py tests/totp-provision-contract-test.php
git commit -m "feat: add restricted remote TOTP signer"
```

### Task 5: Provision the Linux browser host and daily dispatcher

**Files:**
- Create: `scripts/amazon-returns/run-seller-central-daily.sh`
- Create: `scripts/provision-seller-central-browser-host.sh`
- Create: `deploy/systemd/amazon-returns-seller-central-browser.service`
- Create: `deploy/systemd/amazon-returns-seller-central-browser.timer`
- Create: `tests/linux-browser-host-provision-test.php`
- Modify: `scripts/provision-production.sh`

**Interfaces:**
- One-shot service invokes both Seller Central workers with `--drain` serially, with write behavior still controlled by backend gates.
- Timer invokes the service once per day.
- Runner starts/uses the dedicated browser profile and terminates only the dedicated CDP browser after drain.

- [ ] **Step 1: Add failing Linux provisioning tests**

Require daily timer semantics, a persistent profile directory, credential-file references, restricted TOTP SSH options, `--drain`, serial execution, and cleanup that targets only the dedicated CDP/browser PID. Assert no Amazon credential or TOTP value appears in unit templates.

- [ ] **Step 2: Verify RED**

```bash
php tests/linux-browser-host-provision-test.php
php tests/production-provision-test.php
```

- [ ] **Step 3: Implement the one-shot service/timer and provisioning hooks**

Use environment files containing only paths/configuration, not committed secrets. Do not create/enable any new external write flag. Preserve KOCEPSV/Fred-Win compatibility until the Linux path is accepted.

- [ ] **Step 4: Verify GREEN**

```bash
php tests/linux-browser-host-provision-test.php
php tests/production-provision-test.php
bash -n scripts/amazon-returns/run-seller-central-daily.sh scripts/provision-seller-central-browser-host.sh scripts/provision-production.sh
```

- [ ] **Step 5: Commit**

```bash
git add scripts/amazon-returns/run-seller-central-daily.sh scripts/provision-seller-central-browser-host.sh deploy/systemd/amazon-returns-seller-central-browser.service deploy/systemd/amazon-returns-seller-central-browser.timer tests/linux-browser-host-provision-test.php scripts/provision-production.sh
git commit -m "feat: run Seller Central browser bridge daily on VM"
```

### Task 6: Full repository verification and integration

**Files:** all changes above.

- [ ] **Step 1: Run all PHP tests**

```bash
set -e
for test in tests/*.php; do php "$test"; done
```

- [ ] **Step 2: Run all Node tests and static checks**

```bash
node --test tests/*.test.mjs
node --check scripts/amazon-returns/seller-central-auth.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
```

- [ ] **Step 3: Run Python, tenant SQL and shell checks**

```bash
python3 -m unittest tests/totp-current.test.py
php scripts/audit-tenant-sql.php
find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n scripts/auto-deploy.sh scripts/provision-production.sh scripts/verify-live-tenant-foundation.sh scripts/amazon-returns/run-seller-central-daily.sh scripts/provision-seller-central-browser-host.sh scripts/provision-amazon-totp-authenticator.sh
git diff --check origin/main...HEAD
```

- [ ] **Step 4: Inspect for secret-like additions and prohibited SSH weakening**

```bash
! git grep -n -E 'StrictHostKeyChecking=no|BEGIN OPENSSH PRIVATE KEY|otpauth://|SELLER_CENTRAL_PASSWORD=' -- ':!docs/superpowers/plans/*'
```

- [ ] **Step 5: Push and create PR, then follow required checks to green**

```bash
git push origin feat/seller-central-remote-totp
```

Open a PR against `main`, review its exact diff/head, fix any failure, merge only the validated head and leave no task-owned PR/check pending.

### Task 7: Deploy and validate the two-VM fake-seed architecture

**Runtime hosts:**
- Browser: `shopvivaliz-free-a1`
- TOTP: `always-free-arm-1787907847-26`
- Fallback browser: `DESKTOP-KOCEPSV`

- [ ] **Step 1: Follow the existing auto-gate until the merge SHA is the deployed release SHA**

Do not manually copy application release files into production.

- [ ] **Step 2: Provision the authenticator VM with no real seed**

Install the restricted command/user and dedicated authorized key. Generate browser-host SSH keys on their respective browser hosts; copy only public keys to the authenticator authorized-key file. Pin the authenticator host key in each browser host known-hosts file. Do not display any private key.

- [ ] **Step 3: Run an end-to-end disposable fake-seed test**

Temporarily install an RFC/test-only seed on the authenticator VM, request a code from the browser VM through the exact restricted SSH path, verify only `^[0-9]{6}$` is returned, verify direct shell/forwarding is denied, and scan both sides' logs for absence of the seed/code.

- [ ] **Step 4: Remove the disposable seed and prove fail-closed readiness**

Delete only the disposable seed file. A subsequent request must fail with a non-secret `SEED_NOT_CONFIGURED` condition and must not return six digits.

- [ ] **Step 5: Provision browser runtime without enabling new writes**

Install/configure the on-demand browser profile, credential-file locations, bridge token reference, remote TOTP SSH settings and daily timer. Confirm API cycles remain four-hour cadence. Validate KOCEPSV can be configured as standby without a seed.

- [ ] **Step 6: Verify production health and queues**

Confirm application service health, exact release SHA, outbox/DLQ state, write-gate values unchanged, timer state, last API-cycle cadence metadata and browser/TOTP readiness metadata. The daily browser job must remain fail-closed until real Amazon enrollment is complete.

- [ ] **Step 7: Enrollment handoff**

Only now notify the owner that infrastructure is ready. Ask the owner to open Amazon two-step verification and select **Adicionar um novo aplicativo**. The real seed/QR must be transferred directly to the authenticator VM through the secure enrollment path, never pasted into chat. Generate one real code from the authenticator VM for Amazon confirmation, then perform one controlled Seller Central login and verify persistent session reuse. CAPTCHA/recovery remains human-gated.