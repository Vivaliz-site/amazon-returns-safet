# SAFE-T D+45 / owner grant #3556 — staged one-action profile

Status: **SCOPED CANARY PREPARATION ONLY / NOT ACTIVATED**. This file is not a production
authorization mechanism, an operator, or a replacement for Amazon eligibility.

**Production owner directive — 2026-11-13 (takes precedence over the historical
kill-switch-ON instructions below):** the owner expressly requested and
reconfirmed enabling all seven external write actions. PR #695 merged this
change; the active full-recovery profile is a separate decision, not an
activation of scoped grant #3556/#3846. **Audits and corrective maintenance
must not turn the global kill switch ON or disable any of those seven channels
merely to apply this historical scoped-canary runbook.** Evaluate every actual
SAFE-T/support/ERP operation with its own eligibility, deduplication,
authorization, lease/fencing and external readback. The one-use renewed grant
does not by itself authorize unrelated actions.

The original approved grant is
`Vivaliz-site/site-shopvivaliz@main:security/owner-approvals/3556.json`.
**It expired on 2026-11-09 at 23:59 UTC.** The owner-approved, merged
renewal is `security/owner-approvals/3846.json` in the same repository
(PR #3846, merged 2026-11-09). The renewal declares `renews_grant: 3556`,
`one_use: true` and **does not specify `expires_at_utc`**; it retains the
same host, principal, tenant, connection, eight-case canary and single
`SAFE_T_SUBMIT` write capability. The renewed document is not an execution
record: current grant validity, prior one-use consumption, effective RBAC,
lease/fencing, idempotency and the external Amazon state must still be
verified independently before any operation.

The isolated profile at `deploy/write-profile-safe-t-pr3556.json` permits
**only `SAFE_T_SUBMIT`**; the other six external action types are explicitly
false. The default full-recovery profile is unchanged. No environment file,
systemd unit, timer, worker or runtime configuration selects this new profile.
This staged scoped-profile file does not itself change the **current production
write configuration**, which was enabled at the owner's request. Do not select
it or change the global kill switch merely because it exists. Only a separate
operator with approved scope and preflight guards can activate this canary.

The renewed, one-use scope is limited to host `marina-a1-4c24g`, principal
`amazon-returns-safet-service`, tenant 1, connection 1, case IDs
`1263,1314,8803,13210,51429,101669,101670,101671`. The expiration
on 2026-11-09T23:59:00Z belongs **only to #3556**, not #3846.
An operator with proven authorization must atomically enforce all constraints
at runtime: exact eight-case canary (not merely the file contents),
expiration, one-use record, single-writer lease/fencing, evidence of
Amazon-initiated refunds at D+45..D+89, warehouse physical receipt absent,
unrecovered financial debit, support/claim deduplication and official
Seller Central eligibility. The three UNKNOWN initiators require independent
Amazon-initiated proof. Pending outbox entries are NOT proof of Amazon-accepted claims. Historical
reconciliation in issue #532 already identified an external claim for case
#8803; the live external state always wins over the original eight-case list.
Case #42471 is **not** within the renewed eight-case allowlist. Never substitute
cases, duplicate claims or reprocess outbox without current external readback.

The presence of grant #3846 does **not** certify an executable
`activate_scoped_safe_t_opening` operator or prove that its one-use entitlement
remains unconsumed. Until an effective service-principal operator is verified
with lease and all existing guards, do not activate
this scoped profile or change the owner's separate full-recovery write mode.
For a future expressly authorized temporary scoped canary, the operator must
preserve the agreed preflight/rollback for that operation and restore the
owner-approved **pre-operation production state**, including on interruption,
without silently disabling the current authorized seven-channel mode.
An Amazon ineligibility decision must be recorded, not bypassed.

The synthetic regression
`php tests/scoped-safe-t-pr3556-profile-test.php` verifies the seven
action flags and eight-case canary behavior **only in memory**. It is NOT
proof of production authorization, API/UI eligibility, claim submission,
external readback, financial recovery, or restore-on-crash. Do not call the
task complete until these are independently evidenced in issue #532.
