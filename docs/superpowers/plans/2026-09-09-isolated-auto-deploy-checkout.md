# Isolated Auto-Deploy Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make production auto-deploy independent from any developer/agent working checkout while preserving CI gating and the hourly timer.

**Architecture:** Provision and maintain a dedicated clean checkout at `/home/ubuntu/amazon-returns-deploy-source`. The systemd deploy service explicitly passes that directory through `AMAZON_RETURNS_REPO`, while `/home/ubuntu/amazon-returns-safet` remains a human/agent workspace that is never reset or cleaned by deploy automation.

**Tech Stack:** Bash, systemd, Git/GitHub CLI, PHP/Node CI, Ubuntu production VM.

**Spec:** `docs/superpowers/specs/2026-09-09-isolated-auto-deploy-checkout-design.md`

## Global Constraints

- Never reset, clean, checkout, or otherwise mutate `/home/ubuntu/amazon-returns-safet` as part of deployment.
- Dedicated deploy source is `/home/ubuntu/amazon-returns-deploy-source` and tracks only `origin/main`.
- Production releases remain under `/home/ubuntu/amazon-returns-deploy`.
- `amazon-returns-deploy.timer` must use `OnUnitActiveSec=3600` and must never use 300 seconds.
- Existing TOTP/authenticator architecture and concurrent agent work must remain untouched.
- Completion requires automated CI plus live production verification.

---

### Task 1: Lock the dedicated-checkout contract

**Files:**
- Modify: `tests/auto-deploy-test.php`
- Modify: `tests/production-autonomy-config-test.php`

**Interfaces:**
- Consumes: systemd unit text and `scripts/auto-deploy.sh` contract.
- Produces: failing assertions requiring the dedicated checkout path and forbidding the work checkout in the deploy service.

- [ ] **Step 1: Write the failing test**

Add assertions that `deploy/systemd/amazon-returns-deploy.service` contains `Environment=AMAZON_RETURNS_REPO=/home/ubuntu/amazon-returns-deploy-source`, that `ExecStart` points to `/home/ubuntu/amazon-returns-deploy-source/scripts/auto-deploy.sh`, and that the unit does not reference `/home/ubuntu/amazon-returns-safet/scripts/auto-deploy.sh`.

- [ ] **Step 2: Run the tests to verify RED**

Run: `php tests/auto-deploy-test.php && php tests/production-autonomy-config-test.php`
Expected: FAIL because the current service still points to `/home/ubuntu/amazon-returns-safet` and has no dedicated repo environment.

- [ ] **Step 3: Commit test-only RED state**

Commit message: `test: require isolated auto-deploy checkout`

### Task 2: Point auto-deploy service at the isolated checkout

**Files:**
- Modify: `deploy/systemd/amazon-returns-deploy.service`
- Modify: `scripts/auto-deploy.sh`

**Interfaces:**
- Consumes: `AMAZON_RETURNS_REPO` environment variable.
- Produces: deploy process that reads and advances only `/home/ubuntu/amazon-returns-deploy-source`.

- [ ] **Step 1: Implement the minimal service change**

Set `Environment=AMAZON_RETURNS_REPO=/home/ubuntu/amazon-returns-deploy-source` and change `ExecStart` to `/home/ubuntu/amazon-returns-deploy-source/scripts/auto-deploy.sh`.

- [ ] **Step 2: Make the script default safe**

Change the `repo` default in `scripts/auto-deploy.sh` from `/home/ubuntu/amazon-returns-safet` to `/home/ubuntu/amazon-returns-deploy-source`, retaining the explicit environment override and all existing dirty-checkout, CI-green, fast-forward, and provisioning gates.

- [ ] **Step 3: Run focused tests to verify GREEN**

Run: `php tests/auto-deploy-test.php && php tests/production-autonomy-config-test.php`
Expected: PASS.

- [ ] **Step 4: Commit**

Commit message: `fix: isolate auto-deploy source checkout`

### Task 3: Provision the dedicated source safely

**Files:**
- Modify: `scripts/provision-production.sh`
- Test: `tests/auto-deploy-test.php`

**Interfaces:**
- Consumes: Git authentication already available to user `ubuntu` and repository `Vivaliz-site/amazon-returns-safet`.
- Produces: clean `/home/ubuntu/amazon-returns-deploy-source` owned by `ubuntu`, with `origin/main` available and no mutation of the work checkout.

- [ ] **Step 1: Add failing provisioning assertions**

Require `scripts/provision-production.sh` to reference `/home/ubuntu/amazon-returns-deploy-source`, create/clone it when missing, fetch `origin main`, and install the deploy unit from the active release without reset/clean operations against `/home/ubuntu/amazon-returns-safet`.

- [ ] **Step 2: Run focused test to verify RED**

Run: `php tests/auto-deploy-test.php`
Expected: FAIL because provisioning does not yet create the dedicated source.

- [ ] **Step 3: Implement dedicated checkout bootstrap**

Before enabling the deploy timer, ensure the directory exists. If absent, clone `git@github.com:Vivaliz-site/amazon-returns-safet.git` as `ubuntu` into `/home/ubuntu/amazon-returns-deploy-source`. If present, verify it is a Git checkout with the expected `origin`. Fetch `origin main` as `ubuntu`, checkout `main` only inside this dedicated directory, and fast-forward it to `origin/main`. Abort rather than destructively cleaning if this dedicated checkout is unexpectedly dirty.

- [ ] **Step 4: Keep the work checkout untouched**

Do not add any `git reset --hard`, `git clean`, branch checkout, permission rewrite, or other mutation against `/home/ubuntu/amazon-returns-safet`.

- [ ] **Step 5: Run focused tests to verify GREEN**

Run: `php tests/auto-deploy-test.php && php tests/production-autonomy-config-test.php`
Expected: PASS.

- [ ] **Step 6: Commit**

Commit message: `feat: provision isolated deploy source`

### Task 4: Full verification, review, merge and production proof

**Files:**
- Verify all files changed by Tasks 1-3 plus the design/spec and this plan.

**Interfaces:**
- Consumes: green CI and production host access.
- Produces: merged `main`, active production release, isolated deploy source, hourly timer, and evidence that a dirty work checkout no longer blocks deployment.

- [ ] **Step 1: Run full repository verification**

Run the same PHP, Node, TOTP, syntax/security and tenant-isolation checks used by GitHub Actions. Expected: all pass with zero failures.

- [ ] **Step 2: Request code review and fix Critical/Important findings**

Review specifically for accidental mutation of `/home/ubuntu/amazon-returns-safet`, unsafe Git operations, credential leakage, and systemd path mistakes.

- [ ] **Step 3: Merge only after CI is green**

Merge the implementation PR to `main` and wait for `main` CI success.

- [ ] **Step 4: Bootstrap the dedicated checkout on production without touching the work checkout**

Preserve and record the work checkout branch/status before and after. Create/synchronize `/home/ubuntu/amazon-returns-deploy-source` using the approved bootstrap path.

- [ ] **Step 5: Install/refresh the systemd unit and timer from the merged release path**

Verify `systemctl cat amazon-returns-deploy.service` shows the dedicated source path and `systemctl cat amazon-returns-deploy.timer` shows `OnUnitActiveSec=3600` with no `300`.

- [ ] **Step 6: Execute one real deploy cycle**

Run `systemctl start amazon-returns-deploy.service`, then inspect its journal. Expected: no `auto_deploy_skipped=dirty_checkout` caused by the separate work checkout; either it deploys the current green `main` SHA or reports `already_current` if already deployed.

- [ ] **Step 7: Verify live application and repository hygiene**

Confirm active release SHA equals current green `main`, application health is `OK`, the work checkout status/branch is unchanged, dedicated source is clean on `main`, no PRs remain open, and no Actions remain queued or in progress.

- [ ] **Step 8: Final evidence report**

Report exact main SHA, active release SHA, systemd service path, timer cadence, work-checkout before/after status, deploy-cycle result, CI run, and health result.