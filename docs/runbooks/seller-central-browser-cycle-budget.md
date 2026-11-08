# Seller Central: bounded daily read/write drains (08/11/2026)

## Root cause and repair

The canonical Marina `amazon-returns-seller-central-browser.service` one-shot
started at 2026-11-08 04:05:24 UTC and was terminated by systemd at 04:35:25
(`Result=timeout`, configured `TimeoutStartSec=30min`). A total of 170
read-only jobs were observed before timeout: 68 SAFE_T_READ, 12
SELLER_SUPPORT_READ and 90 SAFE_T_DISCOVERY, all 90 discovery attempts
remaining `UI_DRIFT/SAFE_T_DISCOVERY_LIST_COVERAGE_UNVERIFIED` on one page.
The service then auto-restarted due to its existing `Restart=on-failure`.

The normal `--drain` loops in **both** read and write Node workers were
unbounded. A large or unresolvable Seller Central queue could therefore
outlive the one-shot timeout and restart the same canonical browser cycle,
without proving external claim absence. The status-bridge idempotency and
lease controls reduce duplicate risk, but do not make an unbounded drain safe.

The existing daily shell runner now exports a **single absolute cutoff**,
20 minutes after the start of its drain phases, as
`SELLER_CENTRAL_CYCLE_DEADLINE_MS`. The existing bridge/read/bridge phases
all use the same cutoff. If it expires, the worker stops **before pulling
another job**, emits a sanitized `drain_budget_exhausted` event and exits
normally. An already acquired job is still completed and its result
acknowledged; there is no kill, cancellation, lease theft, or release
toggle. The pending queue is left for the **existing** next scheduled
canonical cycle. No new worker, timer, runner, browser, or automation is
created. Bad/unbounded deadline input fails closed.

This is a **cycle-liveness repair**, not a fix for Seller Central UI drift.
An unverified empty/last list is still `UI_DRIFT`; it is NEVER treated
as proof of absent claims or as SAFE-T eligibility. Before any external
claim, the grant, certified operator, one-use fencing, external readback,
provider eligibility, financial and warehouse checks remain mandatory.
The global kill switch stays ON and all seven external write actions OFF.

## Evidence and acceptance

- RED: synthetic contracts rejected all three missing-boundedness guards
  on commit `be37b7559ff5ae3e674c38973c076bed12584975`,
  MCP task `1995860e-07b4-4923-a971-544befdafc4f`.
- GREEN: shared deadline helper, runner and both workers pass 3/3 directed
  Node tests, PHP/runtime unaffected. A broader Node TAP run recorded
  205/205 PASS after updating the stale pre-drain assertion.
- Deployment is **not** certified by local tests or this document. The
  protected GitHub PR/CI, main SHA, immutable Marina release, and next
  completed real daily cycle within 30 minutes must be read back and
  recorded in Amazon Returns issue #532 before claiming runtime resolution.
