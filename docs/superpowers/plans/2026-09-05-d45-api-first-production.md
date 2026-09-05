# D45 API-first production correction plan

**Goal:** Deploy the user-approved D+45 operational opening rule and correct proven processing defects without enabling external writes.
**Architecture:** Keep tenant-scoped repositories, append-only observations and explicit execution adapters. Separate ShopVivaliz operational rules from external Amazon policy evidence.
**Spec:** User-approved requirements consolidated on 2026-09-05; the D+75 statement in the 2026-09-04 SaaS spec is superseded.

## Invariants
- D+45 from the existing seller-debit/refund basis, never silently D+60/D+75. Actual Amazon preflight blocks must remain explicit; no duplicate submissions.
- SAFE-T -> analyzed denial -> appeal in same SAFE-T -> analyzed second denial -> detailed email review -> analyzed response. Missing data is not a denial.
- Recovered only after released nonduplicated credit; partial/damaged physical returns must not become received OK.
- Never mark an unexecuted read SUCCEEDED; all external write flags remain OFF.
- Preserve existing dirty main and untracked document; no secrets, cross-tenant SQL or destructive production operations.

## Task 1: Policy and state boundaries
- [ ] RED: actual seeder D44, D45-1s, exact D45 for STANDARD, DBA, FBA_ONSITE; numeric revision ordering; damaged receipt; stale denial stage regression.
- [ ] GREEN: operational versioned D45 definitions/provenance, preserve superseded values, numeric revision sort, discrepancy preservation, stage guards.
- [ ] Run policy, status and tenant repository suites; update superseded design statement.

## Task 2: Financial truth
- [ ] RED: deferred credit, negative reimbursement, duplicate transaction ID, lifecycle changes, mixed currency and v0/v2024 overlap.
- [ ] GREEN: preserve related identifiers; version changing observations; latest-per-ID projection; signed settled credit; conservative overlap reconciliation.
- [ ] Run reliability/SP-API regressions; never infer denial from an empty financial list.

## Task 3: API coverage and audit
- [ ] RED: keyset order batches visit more than 25 orders and remain tenant-scoped.
- [ ] GREEN: per-connection cursor, bounded rotation, aggregate decision/reason/financial audit logs and honest observation counts.
- [ ] Verify SAFE-T read fallback retains discrepant cases and does not fabricate completion.

## Task 4: Release safety
- [ ] RED: committed artifact excludes worktrees/untracked files; internal paths are web-denied.
- [ ] GREEN: Git-derived artifact, verified pre-change backup, bounded freeze/cutover and failure-safe service restoration through existing root deploy runner.
- [ ] Full PHP tests/lint, Node/Bash syntax, tenant SQL and isolation checks; independent review when available.
- [ ] Push branch, PR, green CI, resolve findings and merge; preserve main changes before allowing existing deploy timer.

## Task 5: Production audit
- [ ] Exact deployed SHA, health, preserved case count, D45 policies, zero stale PROCESSING, writes OFF.
- [ ] Observe actual Orders/Gmail/Finances/Reports and scheduler cycles including all order pages; audit aggregate decisions and reconciliations.
- [ ] Record evidence and external blockers without claiming unexecuted actions succeeded.

Baseline: 39 PHP test files and tenant SQL audit passed using isolated TMPDIR (host /tmp is root-only). Production is defe974 with 39 cases and writes OFF. Existing main patch preserved with SHA256 8bbe3a30744ec3361b068170788126a87bbcabf5a4a1d47c6c71cd3c7dff57a3.
