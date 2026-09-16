# Real End-to-End Audit Governance

## Purpose

This document defines the mandatory audit standard for all Vivaliz software projects. A feature, routine, integration, worker, scheduler, write path, UI flow, or external action may not be classified as ready based only on code presence, unit tests, logs, process liveness, health endpoints, or configuration existence.

The audit must prove that the promised real-world effect actually happens and remains observable after completion.

## Core rule

A routine is **APTO** only when the audit has evidence for all applicable stages:

1. real input was accepted through the actual user/system entry point;
2. the backend processed the same input through the intended production path;
3. the intended side effect occurred in the real target system;
4. the result was read back from the target system or independently reconciled;
5. the evidence was recorded with enough identifiers to reproduce the verification.

If any mandatory stage cannot be proved, the routine is **NAO APTO**. `OK`, green CI, active services, successful queues, or healthy dependencies never override missing end-to-end proof.

## Mandatory evidence chain

For every critical routine, the audit record must identify:

- entry point used: UI, API, webhook, scheduler, worker, CLI, or external event;
- input identity: case/order/patient/invoice/message/task or equivalent stable identifier;
- internal processing evidence: state transition, event, queue item, job, database record, or trace;
- external write evidence when applicable: external ID, transaction ID, message ID, invoice ID, claim ID, calendar event ID, or equivalent;
- readback evidence from the destination;
- final business state expected by the user;
- timestamp and environment/commit/release used for validation.

Synthetic mocks may support tests but cannot replace production-like evidence for a feature declared ready.

## Contradictory audit

After the happy path succeeds, the auditor must try to disprove readiness. At minimum, test the failure modes applicable to the routine:

- write flag disabled;
- missing or expired credential/session;
- queue/worker stopped or stalled;
- scheduler not dispatching;
- dependency unavailable;
- UI drift or changed external API response;
- duplicate/retry behavior;
- partial write followed by uncertain response;
- stale data falsely satisfying health checks;
- readback mismatch;
- permission or tenant-scope mismatch.

The feature remains APTO only if failures are detected, surfaced correctly, do not create silent data loss or duplicate side effects, and recovery is proven.

## Health semantics

Health must reflect business capability, not only process liveness.

A mandatory production capability is degraded when any required write gate is disabled, required credential or session is unusable, a worker/scheduler is not executing within its SLA, a backlog is stuck, readback cannot confirm external writes, or the last successful real execution exceeds its freshness threshold.

A global health status must never be `OK` when a required business capability is unavailable. Health output must distinguish at least `OK`, `DEGRADED`, and `FAILED`, with the blocking capability named explicitly.

## Write gates and feature flags

All production write gates must be included in the audit inventory. The audit must compare three things: expected product behavior, effective runtime configuration, and actual execution evidence.

A feature required by product scope but disabled by a gate is NAO APTO until the gate is intentionally enabled and a real end-to-end execution is confirmed. A disabled gate must not be treated as a harmless configuration detail.

## External integrations

For every integration that changes an external system, success requires target-side confirmation. Local logs that say `submitted`, `accepted`, `queued`, or `200 OK` are insufficient on their own.

The preferred confirmation order is:

1. official API readback;
2. supported target-system query;
3. authenticated UI verification;
4. independent reconciliation from authoritative downstream data.

If none is available, the routine cannot be considered fully proven and must remain explicitly degraded or pending validation.

## Idempotency and retries

Every external write path must prove that retries do not duplicate the business action. The audit must execute or simulate the retry boundary and verify either the same external object is returned or a duplicate is prevented by a stable idempotency rule.

Uncertain responses after an external write must trigger readback/reconciliation before any retry that could duplicate the action.

## UI audit rule

When users operate a feature through the UI, the audit must exercise the UI path itself at least once. Backend-only tests do not prove that the user can complete the task.

The UI verification must confirm discoverability, understandable labels, valid input handling, success/failure feedback, persisted state, and the final business effect.

## Schedulers, workers and autonomous routines

For autonomous routines, the audit must prove actual dispatch over the intended scheduler/daemon path, not only direct invocation of the underlying function.

Required checks include cadence, last successful run, stuck jobs, starvation, dead letters, duplicate execution, disabled schedules, runtime ownership, and whether the autonomous path survives without local operator machines when the architecture requires server independence.

## Project readiness decision

A project is APTO only if every mandatory production routine is APTO or explicitly excluded by approved scope. One unproven mandatory routine makes the project NAO APTO.

The audit report must list each routine with status, real proof, blocking defect if any, and remediation status. Aggregate percentages or green dashboards may be shown, but they may not hide an unproven critical routine.

## Regression requirement

Every defect discovered by a real audit must produce a durable regression guard appropriate to the failure: automated test, runtime invariant, health rule, monitoring alert, reconciliation check, or documented production probe. The guard must target the root cause, not only the observed symptom.

## Canonical audit workflow

For each routine:

1. map expected business behavior;
2. identify all gates, dependencies and execution boundaries;
3. run the real entry path;
4. trace processing across boundaries;
5. verify the real side effect;
6. perform target-side readback/reconciliation;
7. run contradictory/failure-path checks;
8. verify retry/idempotency behavior where applicable;
9. record evidence;
10. classify APTO or NAO APTO;
11. add regression protection for every discovered gap;
12. rerun the full routine after remediation.

## Enforcement across repositories

After approval of this specification, each active project repository must receive a repository-level audit instruction referencing this standard, plus project-specific routine inventory and validation commands/probes. Projects must not mark issues, releases, milestones, or "ready" audits complete until this gate passes.

Existing projects previously labeled ready must be re-audited under this standard. Prior green results do not grandfather a feature that lacks real end-to-end evidence.

## Subagent supervision and takeover

Delegating work to a subagent never transfers completion ownership from the controlling agent. Every subagent dispatch must be actively supervised from the moment it starts.

The controller must record the subagent/session identity, start time, baseline commit or workspace state, expected output artifacts, and the concrete evidence that will count as progress. While the subagent is running, the controller must perform bounded progress checks instead of waiting silently or requiring the user to ask for continuation.

A progress check must look for verifiable movement such as changed files, commits, test output, report artifacts, completed tool actions, or other task-specific evidence. Process existence alone is not progress.

If the subagent errors, loses authentication/quota/tool access, exits without required artifacts, or shows no verifiable progress together with evidence that it is idle, blocked, wedged, or past the task's bounded execution window, the controller must preserve useful work and assume the task directly or dispatch a clean replacement.

The user must never need to send `continue`, `siga`, or an equivalent message to recover a delegated task. Subagent monitoring is part of execution and is itself a mandatory reliability requirement.
