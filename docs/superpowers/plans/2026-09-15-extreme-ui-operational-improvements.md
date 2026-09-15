# Extreme UI Operational Improvements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Amazon Returns cockpit faster, safer and clearer for a real operator, prioritizing actions requiring human attention while preserving all SAFE-T and financial business rules.

**Architecture:** Keep the current PHP API and domain layer unchanged unless a query/filter needs server-side support. Consolidate presentation behavior into the cockpit assets, remove overlapping DOM patches, and make review actions explicit and stateful. Every behavioral change is test-first and validated on desktop and 390px mobile.

**Tech Stack:** PHP 8.1, vanilla JavaScript, CSS, MySQL/PDO, existing PHP contract/regression tests.

**Spec:** `docs/superpowers/specs/2026-09-10-autonomy-cockpit-ux-design.md`

## Global Constraints
- Preserve existing SAFE-T, appeal, finance and learned-rule business decisions.
- Do not change external-write enablement flags.
- Do not expose backend enums or technical language to operators.
- Desktop and 390px mobile must remain usable without horizontal scrolling.
- Timeline/details remain collapsed by default.
- Follow TDD: failing focused test before implementation, then focused suite, full suite, UI smoke.
- Do not overwrite concurrent work; this plan runs only in `feat/ui-operational-extreme-20260915`.

---### Task 1: Review Decision Center + Timeline + Learned Rules

**Files:**
- Modify: `admin/amazon-returns/index.php`
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/cockpit.css`
- Modify: `admin/amazon-returns/assets/cockpit-operational.js`
- Modify/Delete behavior: `admin/amazon-returns/assets/ux-polish.js`
- Test: `tests/cockpit-ui-contract-test.php`
- Test: `tests/cockpit-timeline-test.php`
- Test: `tests/learned-rule-admin-test.php`

**Interfaces:**
- Produces one explicit review decision state, one final confirmation CTA, protected learned-rule disable action, and a single collapsed history control.

- [ ] Add failing contract tests for one history toggle, explicit review selection state, one final confirmation CTA, and styled/protected learned-rule controls.
- [ ] Run focused tests and confirm the new assertions fail for the expected missing behavior.
- [ ] Implement the minimal review/timeline/rule UI changes without changing decision payload semantics.
- [ ] Run focused tests until green.
- [ ] Run cockpit/review/rule regression tests.
- [ ] Commit the independently testable P0 UI safety changes.

### Task 2: Action-First Dashboard + Case List/Detail

**Files:**
- Modify: `admin/amazon-returns/index.php`
- Modify: `admin/amazon-returns/assets/cockpit-summary.js`
- Modify: `admin/amazon-returns/assets/cockpit-operational.js`
- Modify: `admin/amazon-returns/assets/cockpit-operational.css`
- Test: `tests/cockpit-summary-ui-test.php`
- Test: `tests/cockpit-operational-audit-test.php`
- Test: `tests/cockpit-ui-contract-test.php`

**Interfaces:**
- Produces compact top KPIs, action-first work area, compact case rows, and progressive disclosure for operational telemetry/detail.

- [ ] Add failing tests for compact top summary, cases-before-telemetry hierarchy, compact row fields, and collapsed secondary detail sections.
- [ ] Verify RED.
- [ ] Implement the dashboard hierarchy and case presentation changes.
- [ ] Verify GREEN with focused tests.
- [ ] Run cockpit suites and commit.

### Task 3: Mobile Search/Filters + Intake Polish

**Files:**
- Modify: `admin/amazon-returns/index.php`
- Modify: `admin/amazon-returns/intake.php`
- Modify: `admin/amazon-returns/assets/cockpit.css`
- Modify: `admin/amazon-returns/assets/cockpit-operational.css`
- Test: `tests/cockpit-ui-contract-test.php`
- Test: `tests/intake-ux-daily-cadence-test.php`
- Test: `tests/erp-sales-return-ui-contract-test.php`

**Interfaces:**
- Produces full-width mobile search, quick chips plus collapsible advanced filters, and structured intake result/confirmation UX.

- [ ] Add failing mobile/intake contract assertions.
- [ ] Verify RED.
- [ ] Implement responsive search/filter progressive disclosure and intake cards/photo/summary polish.
- [ ] Verify GREEN, run intake/cockpit suites, and commit.
### Task 4: Query Efficiency + Session UX

**Files:**
- Modify: `admin/amazon-returns/api/cases.php`
- Modify: `includes/amazon-returns/CockpitFilters.php`
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/login.php`
- Test: `tests/cockpit-search-native-pdo-test.php`
- Test: `tests/cockpit-api-contract-test.php`
- Test: `tests/admin-auth-test.php`

**Interfaces:**
- Produces server-side operational bucket filtering, no duplicate initial case load, and explicit expired-session recovery.

- [ ] Add failing tests for server-side bucket filtering, single initial case load contract, and 401/session-expired UX.
- [ ] Verify RED.
- [ ] Implement minimal API/filter/session behavior.
- [ ] Verify GREEN with focused tests.
- [ ] Run API/auth/cockpit suites and commit.

### Task 5: Front-End Consolidation + Final UI Validation

**Files:**
- Modify: `admin/amazon-returns/assets/cockpit.js`
- Modify: `admin/amazon-returns/assets/cockpit-operational.js`
- Modify: `admin/amazon-returns/assets/cockpit-operational-bootstrap.js`
- Modify: `admin/amazon-returns/assets/operator-language.js`
- Modify/remove: `admin/amazon-returns/assets/ux-polish.js`
- Modify: `admin/amazon-returns/index.php`
- Test: existing cockpit/review/intake regression suites

**Interfaces:**
- Produces one clear owner per presentation behavior and removes overlapping runtime monkey patches where safe.

- [ ] Add or tighten tests that prevent duplicate overrides/history wrapping and preserve operator-language output.
- [ ] Verify RED for the consolidation-specific invariant.
- [ ] Consolidate only behavior touched by Tasks 1-4; do not refactor unrelated domain code.
- [ ] Run focused tests, then the complete PHP test suite.
- [ ] Render desktop and 390px mobile UI for dashboard, review, case detail and intake; inspect for overflow, hierarchy and action clarity.
- [ ] Run production-safe health/read smoke after merge/deploy path is ready.
- [ ] Commit final consolidation and record validation evidence.
