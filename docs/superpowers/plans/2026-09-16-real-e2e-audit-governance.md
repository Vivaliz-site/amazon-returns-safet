# Real End-to-End Audit Governance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the command `auditoria extrema` invoke a mandatory real end-to-end audit standard across all active projects and prevent any project/routine from being marked ready without real-world proof.

**Architecture:** Use one canonical governance spec as the source of truth, then add a repository-level audit contract and routine inventory in each project. CI/runtime checks must validate the local contract, while project-specific probes prove UI/API/backend/external side effects/readback. Health/readiness must reflect missing mandatory capabilities instead of reporting false-green status.

**Tech Stack:** GitHub repositories, repository instruction files, project CI, runtime health/readiness endpoints, UI/API probes, external-system readback/reconciliation.

**Spec:** `docs/superpowers/specs/2026-09-16-real-e2e-audit-governance-design.md`

## Global Constraints

- `auditoria extrema` means the full real end-to-end audit without requiring the user to restate the rules.
- Code presence, green CI, healthy processes, logs, queues, or `200 OK` are never sufficient proof by themselves.
- A mandatory routine is `APTO` only with real entry-path evidence, internal processing evidence, real target-side effect, readback/reconciliation, and recorded proof.
- Any unproven mandatory routine makes the project `NAO APTO`.
- UI-operated features must be exercised through the UI at least once.
- External write paths require target-side confirmation and idempotency/retry validation.
- Autonomous routines require proof through their real scheduler/worker path.
- Mandatory disabled gates, stale workers, unusable credentials, stuck queues, or missing readback must degrade project health.
- Previously approved projects must be re-audited; prior green status does not grandfather missing real evidence.

---

### Task 1: Canonical repository audit contract

**Files:**
- Create or modify in each repository: `AUDIT_EXTREMA.md`
- Reference: `docs/superpowers/specs/2026-09-16-real-e2e-audit-governance-design.md`

**Interfaces:**
- Consumes: canonical governance spec.
- Produces: repository-local audit contract that agents can discover without conversation context.

- [ ] **Step 1:** Add `AUDIT_EXTREMA.md` stating that the literal command `auditoria extrema` triggers the full canonical standard.
- [ ] **Step 2:** Add mandatory verdict semantics: `APTO`, `NAO APTO`, and per-routine evidence requirements.
- [ ] **Step 3:** Add prohibition on false-green conclusions from unit tests, logs, liveness, mock-only validation, or disabled production paths.
- [ ] **Step 4:** Add the contradictory-audit requirement and target-side readback requirement.
- [ ] **Step 5:** Commit with `docs: enforce real end-to-end extreme audit contract`.

### Task 2: Project routine inventory

**Files:**
- Create in each repository: `docs/audit/routine-inventory.md`

**Interfaces:**
- Consumes: repository routes, UI flows, workers, schedulers, jobs, write gates, integrations, and user-visible workflows.
- Produces: exhaustive list of mandatory routines and their expected business effects.

- [ ] **Step 1:** Enumerate every user-triggered, scheduled, event-driven, and external-write routine.
- [ ] **Step 2:** For each routine record entry point, internal path, dependencies, gates, external destination, readback mechanism, cadence/SLA, and final business state.
- [ ] **Step 3:** Mark each routine `MANDATORY` or explicitly `OUT_OF_SCOPE` with rationale.
- [ ] **Step 4:** Search source/config/CI for features absent from the inventory and add them before continuing.
- [ ] **Step 5:** Commit with `docs: inventory production routines for extreme audit`.

### Task 3: False-green health prevention

**Files:**
- Modify project health/readiness implementation and tests where applicable.
- Add project-specific health contract tests.

**Interfaces:**
- Consumes: mandatory capabilities and effective runtime gates/dependencies.
- Produces: health that becomes `DEGRADED` or `FAILED` when required business capability is unavailable.

- [ ] **Step 1:** Write failing tests for a mandatory feature whose write gate is disabled while process liveness remains healthy.
- [ ] **Step 2:** Run the focused test and confirm current false-green behavior fails the new expectation.
- [ ] **Step 3:** Implement minimal health aggregation so mandatory capability loss cannot return global `OK`.
- [ ] **Step 4:** Include the exact blocking capability/reason in health output.
- [ ] **Step 5:** Run focused tests, full suite, then runtime smoke.
- [ ] **Step 6:** Commit with `fix: prevent false-green business health`.

### Task 4: Real-proof audit probes

**Files:**
- Create project-specific scripts/workflows under the repository's existing test/audit conventions.
- Store evidence under `artifacts/audit/` or the repository's established artifact path.

**Interfaces:**
- Consumes: routine inventory.
- Produces: reproducible probes and evidence for real input -> internal processing -> external effect -> readback.

- [ ] **Step 1:** For every mandatory routine, define one real or production-like probe using its actual entry path.
- [ ] **Step 2:** Capture stable identifiers and timestamps at every component boundary.
- [ ] **Step 3:** For external writes, query the destination and prove the resulting object/state exists.
- [ ] **Step 4:** Re-run the same logical operation to prove idempotency or duplicate prevention.
- [ ] **Step 5:** Execute one contradictory failure case per applicable dependency/gate and verify the failure is surfaced rather than silently green.
- [ ] **Step 6:** Persist a machine-readable evidence manifest mapping each routine to its proof.
- [ ] **Step 7:** Commit with `test: add real end-to-end audit probes`.

### Task 5: Extreme-audit verdict gate

**Files:**
- Create or modify project audit workflow/report generator under existing CI conventions.
- Create: `docs/audit/latest-verdict.md` or equivalent generated artifact.

**Interfaces:**
- Consumes: routine inventory plus evidence manifest.
- Produces: project verdict that cannot be `APTO` while a mandatory routine is unproven.

- [ ] **Step 1:** Write a failing contract test where one mandatory routine lacks readback evidence and the project is incorrectly considered ready.
- [ ] **Step 2:** Implement verdict aggregation: all mandatory routines `APTO` => project `APTO`; otherwise project `NAO APTO`.
- [ ] **Step 3:** Report blockers by routine with concrete missing proof instead of aggregate percentages alone.
- [ ] **Step 4:** Make release/readiness audit jobs fail when project verdict is `NAO APTO` where CI enforcement is appropriate.
- [ ] **Step 5:** Run contract tests and a dry evidence evaluation against the current repository.
- [ ] **Step 6:** Commit with `ci: enforce extreme-audit readiness verdict`.

### Task 6: Re-audit every previously ready project

**Files:**
- Update each project's `docs/audit/latest-verdict.md` and evidence artifacts.

**Interfaces:**
- Consumes: Tasks 1-5 implemented in each repository.
- Produces: current real readiness classification across projects.

- [ ] **Step 1:** Run the routine inventory against the deployed/current release, not only the source branch.
- [ ] **Step 2:** Execute all mandatory real probes and contradictory checks.
- [ ] **Step 3:** Fix discovered root causes using TDD/systematic-debugging; never waive missing proof.
- [ ] **Step 4:** Rerun each remediated routine from its actual entry point through final readback.
- [ ] **Step 5:** Publish `APTO` only when every mandatory routine has current evidence; otherwise publish `NAO APTO` and exact blockers.
- [ ] **Step 6:** Commit evidence references and final verdict.

### Task 7: Amazon Returns first enforcement case

**Files:**
- Modify: `deploy/write-profile.json`
- Modify: runtime environment/config management for `AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED`
- Modify: `includes/amazon-returns/Runtime.php` health semantics
- Test: existing ERP sales-return and write-profile tests plus new false-green regression tests
- Evidence: ERP return workflow/readback records for real refunded Amazon orders

**Interfaces:**
- Consumes: current ERP sales-return workflow and Olist/Tiny browser gateway.
- Produces: every eligible refunded order reconciled to an ERP sales return with readback, without duplicate creation.

- [ ] **Step 1:** Query the production database and classify all refunded orders by ERP workflow state before enabling writes.
- [ ] **Step 2:** Prove Olist/Tiny browser/session readiness and execute a non-destructive readback/preflight for a known order.
- [ ] **Step 3:** Write a failing regression test proving health cannot remain `OK` when ERP return creation is mandatory but effectively disabled.
- [ ] **Step 4:** Validate business eligibility conditions so only the intended refunded orders enter creation and duplicates/existing return invoices are suppressed.
- [ ] **Step 5:** Enable both required ERP creation gates in the controlled release configuration.
- [ ] **Step 6:** Process one canary refunded order and verify the actual Olist/Tiny sales-return record by readback.
- [ ] **Step 7:** Reconcile the remaining eligible refund backlog, recording per-order external IDs/status and blocking errors.
- [ ] **Step 8:** Confirm every refunded order is either linked to an existing ERP return, newly created and read back, or explicitly blocked with a real external reason requiring remediation.
- [ ] **Step 9:** Rerun the full Amazon Returns extreme audit, including UI, workers, schedulers, SAFE-T, email, Seller Support, ERP, queues, flags, readbacks, retries, and independence from local fallback machines.
- [ ] **Step 10:** Commit with `fix: enforce real ERP returns for refunded orders` only after real readback evidence exists.

## Self-review result

- Spec coverage: all canonical sections are mapped to Tasks 1-6; Amazon Returns is the first concrete enforcement case in Task 7.
- Placeholder scan: no TBD/TODO/"implement later" steps remain.
- Type/interface consistency: repository contract -> routine inventory -> evidence -> verdict is consistent across tasks.
