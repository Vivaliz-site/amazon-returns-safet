# SAFE-T D+45 / owner grant #3556 — staged one-action profile

Status: **PREPARATION ONLY / NOT ACTIVATED**. This file is not a production
authorization mechanism, an operator, or a replacement for Amazon eligibility.

The canonical grant is
`Vivaliz-site/site-shopvivaliz@main:security/owner-approvals/3556.json`.
It was approved by the owner and merged, but the operational service must
still independently verify current grant validity, principal, effective RBAC,
lease/fencing, idempotency and the external Amazon state.

The isolated profile at `deploy/write-profile-safe-t-pr3556.json` permits
**only `SAFE_T_SUBMIT`**; the other six external action types are explicitly
false. The default full-recovery profile is unchanged. No environment file,
systemd unit, timer, worker or runtime configuration selects this new profile.
The global kill switch must remain **ON** until a duly authorized operator
has passed all preflight guards. Merely deploying this JSON must do nothing.

The one-use grant is limited to host `marina-a1-4c24g`, principal
`amazon-returns-safet-service`, tenant 1, connection 1, case IDs
`1263,1314,8803,13210,51429,101669,101670,101671`, and expires
2026-11-09T23:59:00Z. The operator must atomically enforce all constraints
at runtime: exact eight-case canary (not merely the file contents),
expiration, one-use record, single-writer lease/fencing, evidence of
Amazon-initiated refunds at D+45..D+89, warehouse physical receipt absent,
unrecovered financial debit, support/claim deduplication and official
Seller Central eligibility. The three UNKNOWN initiators require independent
Amazon-initiated proof. Pending outbox entries for cases 8803/1263 are NOT
proof of Amazon-accepted claims. Never reprocess them without external readback.

There is **no certified `activate_scoped_safe_t_opening` operator** in the
current Amazon Returns release. Until an effective service-principal operator
with lease and all gates exists, leave the kill switch ON and do not activate
this profile. On an authorized canary, any temporary relaxation must be
restricted to SAFE_T_SUBMIT only and must restore kill switch ON
immediately after the attempt, even on error or interruption. An Amazon
ineligibility decision must be recorded, not bypassed.

The synthetic regression
`php tests/scoped-safe-t-pr3556-profile-test.php` verifies the seven
action flags and eight-case canary behavior **only in memory**. It is NOT
proof of production authorization, API/UI eligibility, claim submission,
external readback, financial recovery, or restore-on-crash. Do not call the
task complete until these are independently evidenced in issue #532.
