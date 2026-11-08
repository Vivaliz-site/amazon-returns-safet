# SAFE-T automatic D+45 activation — P2 #3830

**State: P2 HUMAN REVIEW PENDING / NO PRODUCTION ADAPTER / EXTERNAL WRITES OFF.**

## Two separate authorizations and code review

The owner-grant PR is https://github.com/Vivaliz-site/site-shopvivaliz/pull/3830 .
Its grant file is security/owner-approvals/3830.json. A legitimate
shopvivaliz-human APPROVED event on the exact final HEAD, followed by an
authorized protected merge, is necessary for the P2 operation
activate_d45_safe_t_automation. The user approving in chat alone is not the
GitHub grant event. This does not modify approved #3556 or #3673.

The Amazon Returns PR adds only an **import-only activation core, 16 tests and
this runbook**. It does not install a runtime adapter, grant, browser, worker,
cron, unit, ledger migration, secret, endpoint or production write profile.
The separate code PR also requests shopvivaliz-human review for the
activation feature.

## D+45 remains the first INTERNAL eligibility attempt

Count 45 full days from the confirmed customer refund timestamp refund_at,
never convert the first internal eligibility check into D+60. The canonical
read/scheduler must initiate the official Seller Central eligibility check
at D+45 and record the result.

A new real claim is permitted only when the authenticated Amazon eligibility
UI certifies the exact order as ELIGIBLE on the attempted date, together
with all independent financial and warehouse evidence. If Seller Central
requires waiting until a different date (including D+60), preserve the
official BLOCKED_UNTIL date and resume on it, without duplicate submission.
D+45 never overrides Amazon's actual eligibility. Internal first-opening
window is D+45 through D+89, not D+90. An initial damaged/discrepant-return
claim is manual-only.

## Exact P2 limits

- Owner grant: #3830; one-use controlled activation; expires
  **2026-11-30 23:59 UTC** (exclusive).
- Runtime: marina-a1-4c24g, service principal
  amazon-returns-safet-service, tenant/connection 1/1 only.
- Programs: FBA_ONSITE and DELIVERY_BY_AMAZON when official SAFE-T applies.
- External action: SAFE_T_SUBMIT only. All other six write actions remain
  disabled, including appeal, email review/reply, seller support open/update
  and ERP return creation.
- Dynamic future cases: only eligible tenant 1 / connection 1 after D+45;
  atomic durable limits **20 new claims total / 5 per UTC day**.
- Case #8803 must never be resubmitted (external claim linked). Existing
  SAFE-T or support claims, UNKNOWN refund initiator, received warehouse
  goods, fully reimbursed balances and wrong programs must not be submitted.
- Owner grants #3556/#3673 retain separate one-use fixed canaries and TTLs;
  do not mingle or consume them.

## Production adapter — remaining non-negotiable installation

The exported activateD45AutomaticSafeT function has no CLI, background
process, default adapter or side effects upon import. Its callbacks are a
**trusted adapter contract**, not actual provider attestations. Passing
mocked tests does not certify production authorization.

A separate implementation and official operator installation MUST prove:

1. GitHub-authenticated approved HEAD/merge and immutable grant blob
   #3830, current expiry and revocation; never trust caller-supplied
   verifiedBy text without independent provider verification.
2. Effective restricted service-principal identity, least-privilege RBAC and
   correct tenant/connection, not merely existence of its passwd entry.
3. One real shared single-writer lease with positive fencing across existing
   daemon and Seller Central browser FLOCK. Do not start duplicate workers,
   browsers, GitHub runners or scheduled tasks.
4. Independent crash/SIGKILL/reboot watchdog and actual kill-switch restore
   readback. Never release the lease if restoration is uncertain.
5. Backup, disposable restore and authorized production DB migration for
   atomic one-use activation and unique per-order attempt records. Quotas
   must survive restart and prevent uncertain automatic retries.
6. Fresh per-order WAREHOUSE physical-receipt absence, Amazon-initiated
   refund, positive unrecovered Finances debit, order and item identity,
   exhaustive claim/support deduplication, official eligibility and precise
   provider wait handling. A partial result list is not a no-claim proof.
7. Existing scoped worker only, with SAFE_T_SUBMIT permitted temporarily
   and the other six channels OFF; restore the global switch ON immediately.
8. External SAFE-T claim ID accepted and independently read back in Seller
   Central, then financial credit reconciled separately.

Pre-activation tests include D+45/D+90 boundaries, D+60 provider wait,
UNKNOWN initiators, #8803 duplicate, #13210 support, wrong tenant,
claim idempotency, crash, concurrent writers, quotas, exact granted user,
revocation and UI E2E. Official CI, immutable Marina release SHA readback,
real browser GUI and rollback verification are mandatory.

## Rollback / prohibited shortcuts

Leave AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=1 and all seven effective
write gates OFF until a certified, approved operator installs and validates
all listed controls. Do not change unit User=www-data to another principal
or use sudo/MCP/RDC as a workaround for a provider privilege refusal.

Canonical P0 incident:
https://github.com/Vivaliz-site/amazon-returns-safet/issues/532
