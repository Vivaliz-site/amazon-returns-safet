# SAFE-T Claim Monitoring Must Survive Local Closure

**Date:** 2026-10-31
**Scope:** Amazon Returns / SAFE-T real read-only monitoring, irrespective of local `closed_at`.

## Regression established before implementation
In production 63 cases have an identified SAFE-T claim. The old `CaseRepository::casesWithSafeTId` only selected cases with `closed_at IS NULL`; meanwhile `TenantOutbox::supersedeTerminalReadJobs` cancelled pending `SAFE_T_READ` jobs for any locally closed case. Consequently, historical loss/closure could silently stop an existing Amazon claim from being monitored.

## Behavior
- A known non-empty SAFE-T claim ID always remains eligible for authoritative Seller Central status reads, even when the internal case has a historical closed date.
- Closing a local case must not cancel an outstanding `SAFE_T_READ` job. Terminal cases without claim IDs continue to suppress unnecessary `SAFE_T_DISCOVERY`; Seller Support reads retain the existing closure policy.
- Reads stay tenant-scoped, idempotent, leased and read-only. This correction does not authorize new SAFE-T filings or bypass existing kill switches, eligibility review, write-profile or human-first submission rules.
- Reopen/reclassify cases only after authoritative Amazon evidence or financial evidence justifies state transitions. An approval is not a financial credit.
- For more than 250 claims, monitor coverage/fairness and extend scheduling if old-ID ordering starves newly discovered claims. Do not certify comprehensive V5 until real per-case readback and UI journeys are confirmed.
