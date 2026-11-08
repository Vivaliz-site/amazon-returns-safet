# 2026-11-06: D+45 SAFE-T write blockers - provenance and safe recovery

The owner reported that SAFE-T automation formerly worked and requested investigation and removal of restrictions added without approval. Absence of a GitHub review alone is not conclusive proof of missing consent.

## Observed source and runtime

- Git origin 6053a92 (2026-09-05) introduced the global write kill switch. The 2026-09-06 and 09-07 environment backups already have legacy SAFE_T_WRITE, APPEAL_WRITE and SUPPORT_WRITE flags set to 0. They were therefore not newly disabled in November.
- PR #567 (2026-11-03) changed missing/invalid kill-switch values to fail closed. Its GitHub review list has no human review submission. This did not alter the actual explicit runtime value 1 (already ON). Reverting missing-value protection alone would not reenable submissions and could accidentally enable every external action during a configuration outage.
- Owner grants in site-shopvivaliz #3556 and #3673 have verified shopvivaliz-human APPROVED reviews. Agent-generated Amazon Returns PRs #559 and #580 intentionally staged exact-case profiles but did not activate them; their review lists do not contain human submissions.
- The 2026-11-06 production release 9bb000d21810e0fe2b24cc5f04f58b3cca73d9b4 had AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH=1, empty active case canary and write flags OFF. An admin shell is not proof of the grant's scoped service principal or one-use lease.
- The managed Seller Central browser session on Marina successfully ran real authenticated SAFE_T_DISCOVERY reads on 2026-11-06. The separately isolated helper browser on the backend redirected to sign-in. The helper login status does not mean the managed Seller Central session is unauthenticated.
- Previous SAFE_T_SUBMIT job attempts on 2026-11-04 produced SAFE_T_WRITE_WITHOUT_READBACK_ID and SAFE_T_ITEM_SELECTION_NOT_ACCEPTED. These are historical provider readback/UI conditions, not evidence of successful new submissions.

## Corrective, read-only capability

The canonical managed browser runner accepts the new command:

    run-seller-central-daily.sh --eligibility-check-only ORDER_ID

It acquires the existing cycle flock, starts/uses only the existing profile under its managed process, verifies authentication, checks the precise order's eligibility in the official Seller Central screen and returns a bounded status. It exits before support discovery, write bridge processing, SAFE-T Next/Submit, appeal, support or acknowledgment of a write outbox job. An unmanaged direct invocation is rejected.

Possible statuses: ELIGIBLE (one visible item), ALREADY_EXISTS (exact claim ID), NOT_ELIGIBLE (explicit provider 75-day notice), REVIEW_REQUIRED (multiple items), UI_DRIFT (ambiguous), AUTH_REQUIRED (provider session issue), FAILED. This probe is evidence gathering, not a submission, not a grant and not authorization to bypass the existing external-write kill switch. It cannot certify completeness of the provider's historic claim listing by itself.

## Remaining operational scope

A certified scoped write operator still has to check the owner-approved principal, grant, exact case set, one-use ledger, tenant, concurrency lease, seller debit, warehouse intake, refund initiator, official live eligibility, external claim deduplication and post-submission claim ID. The global write switch remains ON until a fully authorized, fenced activation can restore it in all outcomes. Protect the existing #8803 claim, avoid duplicate #13210 support, and honor provider eligibility rejection. The independent V5 UI and financial certifier decides APTO; this patch alone does not.
## 2026-11-08 code-review corrective follow-up (PR #611)

The required conversation-resolution gate flagged two implementation defects and correctly blocked integration; no branch protection was bypassed.

- P1: a caller-provided environment variable was not proof of the managed browser cycle. The read-only entrypoint now verifies the actual inherited Linux FD 9 FLOCK (/proc/self/fdinfo/9), its device/inode against the canonical profile lease file and refuses arbitrary lock-file overrides. Cdp.connect with managedExistingOnly=true cannot launch a second browser. A direct invocation with only a forged environment variable fails closed.
- P2: the eligibility snapshot now traverses same-origin nested iframes recursively, accepting only a single document containing the exact order/result candidate. Cross-origin/inaccessible or multiple candidates remain inconclusive. Input echo, claim ID, selectable quantity controls and refusal text are read from the same document.
- Regression from main #610: preserve the existing shared write/read/write 20-minute deadline for ordinary drains. Read-only eligibility exits earlier, before write calls.

FLOCK inherited-FD and nested iframe tests cover both findings. Review conversations must be resolved only after the official CI verifies this pushed HEAD. Global write flags and owner grant scopes remain unchanged.
