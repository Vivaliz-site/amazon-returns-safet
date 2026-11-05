# Supplemental SAFE-T scoped grant #3673 — preparation only

Owner-approved and merged authorization source:
`Vivaliz-site/site-shopvivaliz@main:security/owner-approvals/3673.json`.
This grant supplements, but does **not** change or extend, grant #3556.

The staged profile `deploy/write-profile-safe-t-pr3673.json` is intentionally **not selected**
by the production environment, systemd, timer, worker or deployment. The global
`AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=1` remains in effect. The existing
seven external write actions remain **OFF**. The profile has exactly one true
action, `SAFE_T_SUBMIT`, and the others false. The default and #3556 profiles
remain unchanged. Neither a merged owner grant nor this file authorizes a
production write by itself.

The new immutable version `safet-d45-pr3673-scoped-v1` is accepted only with
the **exact** five-case canary `42471,51429,101669,101670,101671`.
Its deadline is exclusive `2026-11-30T23:59:00Z`. The original eight-case
`safet-d45-pr3556-scoped-v1` remains bounded to `2026-11-09T23:59:00Z`;
mixing either canary with the other fails closed. Unknown scoped version names,
missing, malformed, partial, expanded and duplicated canaries fail closed.
Synthetic regressions are in `tests/scoped-safe-t-pr3673-profile-test.php`
(and are included from the existing canonical PR3556 test). Their passing
does **not** prove live Amazon eligibility, finance, claim submission or
restore-on-error.

**Operational blocker remains:** no certified
`activate_scoped_safe_t_opening` exists in this release. The service-principal
operator must prove signed owner grant, one-use, host `marina-a1-4c24g`,
tenant 1, connection 1, lease/fencing and zero concurrent writer; read back
financial debit and physical nonreceipt from independent sources; prove
Amazon-initiated refund, order-specific Seller Central eligibility and
absence of any previous external SAFE-T or duplicate support. The UNKNOWN
initiator of #51429 forbids submission until independently proved. Do not
re-submit #8803: it already has a claim. D+45 is the internal first check;
external seller-specific dates and denials must be honored.

Only the certified service principal may temporarily select this profile
and relax the switch after all guards, then restore
`AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=1` in a guaranteed finally/rollback.
No executor may use these staged files to bypass any provider denial or
replace native UI evidence/readback. Keep #532 OPEN/NAO_APTO until external IDs
and financial reconciliation are documented.
