# Recurring Gemini Worker Isolation Design

**Date:** 2026-09-16
**Repository:** `Vivaliz-site/amazon-returns-safet`
**Status:** Approved for implementation on 2026-09-16

## Context

The global continuity controller runs every 30 minutes and exists to discover abandoned work, maintain leases, reconcile evidence, and resume eligible tasks. A regression previously allowed automatic dispatch to prefer Codex/ChatGPT/Claude before Gemini. PR #214 corrected the code so automatic dispatch can select only `gemini` or `rooter`.

Production is currently fail-closed with `auto_dispatch=false`. Runtime SHA `c0d6ba88bf26530a43360ec37cc7f89e5c00528e` includes the automatic-agent restriction. Gemini CLI 0.60.0 is installed on `shopvivaliz-free-a1`, and a headless smoke using the existing protected Gemini API credential succeeded as the continuity service identity.

The remaining blocker is architectural: `agent-continuity-controller.service` intentionally mounts the observed repository read-only. Giving that controller broad write access would violate its audit boundary and enlarge the blast radius of a recurring AI process.

## Goals

1. Keep the controller read-only with respect to observed repositories and production deploy paths.
2. Run recurring AI work only through Gemini, with `rooter` as a future validated fallback.
3. Never automatically fall back to Codex, ChatGPT, or Claude.
4. Allow an automatic worker to edit only controller-owned isolated worktrees.
5. Preserve task lease, branch, worktree, audit, redaction, and rollback guarantees.
6. Support the lifecycle `resume -> validate -> commit -> push -> PR/checks -> merge -> post-merge verification`.
7. Fail closed whenever identity, credential, worktree ownership, repository scope, or safety evidence is incomplete.

## Non-goals

- Do not make the production checkout writable to the recurring AI worker.
- Do not reuse interactive Codex or Claude sessions as background workers.
- Do not copy broad user GitHub credentials into Gemini prompts or command arguments.
- Do not automate legacy/shared/dirty worktrees until they are deliberately migrated into controller ownership.
- Do not enable `rooter` until its executable, authentication, cost model, and sandbox behavior are independently validated.

## Architecture

The system is split into two trust domains.

### 1. Read-only controller

`agent-continuity-controller.service` remains the authority for discovery, classification, leases, queue ordering, redacted resume packets, and remote-state reconciliation. It continues to run as `agent-continuity` with `NoNewPrivileges=true` and read-only access to observed source repositories.

The controller must not launch Gemini directly against an observed repository. For automatic work it emits a bounded job envelope under `/var/lib/agent-continuity/jobs/` only after all eligibility checks pass.

A job envelope contains non-secret metadata only: task ID, repository, controller-owned worktree path, branch, expected head/base SHA, lease/session identifier, selected agent, resume-packet digest/path, creation time, and execution deadline. It never contains API keys, cookies, GitHub tokens, or private-key material.

### 2. Isolated worker

A separate `agent-continuity-worker` OS account executes recurring Gemini jobs. Its writable filesystem scope is limited to controller-owned worktrees plus worker state/result directories. Production deploy directories and legacy source checkouts remain read-only or inaccessible.

The worker is activated by a root-owned systemd `.path`/service pair watching the job directory. This avoids granting the controller permission to start privileged services. The worker consumes one claimed job at a time, verifies the envelope again, executes Gemini non-interactively, writes a bounded result receipt, and exits.

The worker must never accept an arbitrary path from a prompt. The requested path must resolve beneath `/srv/continuity/worktrees/`, match the ledger record, belong to the worker identity, and contain the expected branch/head before execution.

## Repository and worktree ownership

Automatic tasks use a worker-owned repository mirror/source under `/srv/continuity/sources/<repo>.git` and worktrees under `/srv/continuity/worktrees/<repo>/<task-id>/`. They are independent of `/home/ubuntu/amazon-returns-deploy-source` and of production release directories.

Creating a controller-owned task must be atomic from the controller's perspective: establish repository identity, allocate a unique task ID/branch, create the isolated worktree through the worker-side preparation path, persist expected base/head, then mark the task eligible. Existing paths are never reused implicitly.

A task is ineligible for automatic execution when any of the following is true:

- path is outside the configured worktree root;
- worktree is owned by another user or task;
- branch or HEAD differs from the ledger expectation before launch;
- merge/rebase/cherry-pick is already in progress unless the task explicitly records that recovery state;
- untracked or dirty files are not already represented in the task evidence;
- another unexpired lease exists;
- repository or branch is outside the configured allowlist;
- credentials or required provider health are unavailable.

Legacy findings stay visible in the queue but remain manual until explicitly migrated.

## Agent execution contract

The job runner owns the concrete Gemini CLI command. The command is not accepted from task data and credentials never appear in its argv. The resume packet is supplied through stdin or a protected temporary file, and stdout/stderr are captured into size-bounded, secret-redacted worker logs.

Gemini runs non-interactively with the approved low-cost/fast recurring model configured outside the task envelope. The worker has a hard wall-clock timeout, process-group termination on timeout, and a single automatic job concurrency limit per host.

Recurring execution defaults:

- provider: Gemini only;
- `rooter`: disabled until separately certified;
- Codex/ChatGPT/Claude: structurally forbidden for automatic dispatch;
- one worker job at a time per host;
- one dispatch attempt per controller cycle;
- retry cooldown at least 30 minutes after a launch/provider failure;
- bounded automatic attempts per task before classification becomes `BLOCKED_EXTERNAL` or requires review;
- no background retry loop inside Gemini itself beyond the configured worker policy.

Interactive/manual dispatch remains a separate path and may use other agents only when explicitly requested by the operator. Automatic and manual policy must not share a fallback list.

## Credential boundaries

Gemini receives only a dedicated Gemini credential through a root-owned environment file readable by `agent-continuity-worker`. The value is never stored in the ledger, config JSON, job envelope, resume packet, Git history, or journal output.

The AI worker does not receive the user's broad `gh` OAuth token. Git push uses a repository-scoped credential dedicated to the worker. GitHub API actions such as opening a PR or enabling auto-merge are performed by a non-AI publisher component with a separate protected credential and explicit repository allowlist.

The preferred Git transport is a repository-scoped SSH deploy credential for branch push. The publisher owns PR/check/merge API capability separately. If a narrowly scoped publisher credential cannot be provisioned, automatic execution stops after a validated local commit/branch and reports a reviewable external blocker rather than exposing a broader credential to the AI process.

## Job and result lifecycle

1. Controller reconciles repositories and expires stale leases.
2. Controller selects the highest-priority eligible task.
3. Controller validates automatic-agent policy and worktree ownership.
4. Controller claims the task lease and persists the dispatch attempt.
5. Controller writes an atomic job envelope plus redacted resume packet under the worker queue.
6. The systemd path unit activates the worker.
7. Worker re-validates task ID, repository, branch, head, path, ownership, lease, deadline, and provider.
8. Worker executes Gemini inside only that worktree.
9. Worker validates the resulting Git state and emits a result receipt containing status, resulting head, changed paths, validation commands/results, and bounded diagnostics.
10. Publisher pushes an approved branch and creates/updates the PR without exposing publisher credentials to Gemini.
11. Controller reconciles branch/PR/check state on the next pass.
12. Auto-merge may be enabled only when required checks are green and the task policy permits it.
13. Post-merge reconciliation verifies the exact merged SHA and marks the task `DONE` only after deployment/functional evidence required by that task is present.

A worker result is evidence, never authoritative completion by itself.

## Failure behavior

Every failure is deterministic and fail-closed. A provider/authentication failure, invalid envelope, path mismatch, unexpected branch/head, missing credential, validation failure, timeout, failed push, failed PR creation, or failed CI returns the task to an explicit resumable or blocked state with a persisted reason.

A Gemini failure never triggers Codex, ChatGPT, or Claude. Once `rooter` is certified, fallback may occur only through the same job-envelope, path, lease, credential, timeout, and result-receipt contract.

The worker never resets or cleans a worktree to recover from failure. Existing modifications remain evidence for the next reconciliation.

## Gemini sandbox and policy

Gemini CLI is invoked in headless mode with an explicit model and machine-readable JSON output. Automatic editing must use the Gemini policy engine/admin policy plus OS-level systemd restrictions; a bare unrestricted `--yolo` invocation is not acceptable for production recurring execution.

The effective policy permits only operations required for the repository task inside the selected worktree: read/write project files, run bounded project validation commands, inspect Git state, and create commits. Dangerous filesystem operations, access outside allowed roots, privilege escalation, credential inspection, service management, destructive Git, and arbitrary persistence are denied.

Network access is unnecessary for the AI edit phase except where a task explicitly requires an approved dependency/tool endpoint. Git publication is separated into the publisher component so the Gemini process does not need GitHub API credentials.

## Observability and token accountability

Each provider invocation records task ID, session/lease ID, provider, model, start/end timestamps, exit classification, retry count, worker host, and job/result digests. Where Gemini's JSON output exposes usage metrics, input/output/cached token counts are recorded as numeric telemetry; prompt contents are not persisted beyond the existing redacted resume packet.

Operational views must distinguish `manual` from `recurring` invocation origin. A recurring invocation without a valid task ID and lease is a defect and must alert/fail.

At any time it must be possible to answer: which task caused an AI invocation, which provider/model ran, why it ran, how long it ran, how many retries occurred, and whether it changed Git state.

## Proposed components

- `tools/continuity/job_queue.py`: atomic non-secret job envelope/result receipt handling and eligibility validation.
- `tools/continuity/worker.py`: worker-side revalidation, Gemini invocation, timeout/process handling, result capture, and Git post-state validation.
- `tools/continuity/publisher.py`: non-AI branch publication and PR/auto-merge orchestration behind a repository allowlist.
- `tools/continuity/dispatcher.py`: automatic dispatch emits jobs instead of directly launching an AI process.
- `deploy/systemd/agent-continuity-worker.path`: activates the worker when eligible jobs appear.
- `deploy/systemd/agent-continuity-worker.service`: hardened one-shot worker under the dedicated worker identity.
- `deploy/systemd/agent-continuity-publisher.path` and `.service`: process successful worker receipts without exposing publisher credentials to Gemini.
- `deploy/continuity/gemini-admin-policy.toml` or equivalent supported policy file: recurring Gemini tool restrictions.
- `scripts/install-continuity-controller.sh`: create service identity/directories and install worker/publisher units without overwriting operator-owned secrets.
- Tests covering queue atomicity, path/ownership checks, provider restriction, timeouts, retry bounds, credential non-leakage, systemd contracts, and pilot lifecycle.

The exact module split may be reduced during planning if existing abstractions can own these responsibilities cleanly, but controller and AI worker remain separate trust boundaries.

## Systemd hardening

The worker service runs with `NoNewPrivileges=true`, `PrivateTmp=true`, `PrivateDevices=true`, strict kernel/control-group protections, restrictive umask, an explicit `ReadWritePaths` limited to worker state and `/srv/continuity/worktrees`, and read-only access to only the runtime/policy files it needs.

The worker has no access to production `.env` files. A dedicated root-managed Gemini env file contains only the provider credential/config required by the worker. The publisher credential is mounted only into the publisher service, never the worker service.

Service limits include memory, CPU, process count, and wall-clock execution bounds suitable for a single recurring task. A killed or crashed worker leaves enough receipt/ledger evidence for the controller to reconcile the lease safely.

## Pilot gate before enabling auto-dispatch

`auto_dispatch` remains false until a disposable controller-owned task proves the complete recovery path without touching production business data.

The pilot must demonstrate, with persisted evidence:

1. create a dedicated worker-owned source/worktree and task;
2. establish branch/base/head identity and a short lease;
3. make a harmless test-only change in the isolated worktree;
4. interrupt the first worker or let the lease expire;
5. reconcile `AGENT_LOST -> NEEDS_RESUME` without resetting work;
6. render the same redacted resume packet against the preserved worktree;
7. resume through the Gemini worker under a new lease/session;
8. run repository validation successfully;
9. commit without modifying unrelated files;
10. publish the branch through the non-AI publication channel;
11. create/update a PR, observe required CI, and exercise the configured merge path;
12. reconcile the merged SHA and verify the post-merge state;
13. prove the Gemini worker never received Codex/Claude credentials or the publisher credential;
14. prove a deliberately ineligible legacy path is rejected automatically.

The pilot must be removed/closed cleanly after evidence is captured. Failure of any gate leaves `auto_dispatch=false`.

## Rollout sequence

Phase 1 keeps the current controller timer enabled in audit-only mode while worker code/units are installed but inactive. Phase 2 runs unit/integration security tests and the disposable pilot manually. Phase 3 enables Gemini automatic dispatch for one repository and one concurrent job. Phase 4 observes multiple timer cycles and audits every provider invocation before considering additional repositories or `rooter` fallback.

## Rollback

Rollback is configuration-first and preserves forensic state:

1. set `auto_dispatch=false`;
2. stop/disable worker and publisher path units;
3. leave controller reconciliation/timer running read-only unless it is itself implicated;
4. preserve ledger, job/result receipts, worktrees, branches, and logs;
5. revoke only worker/publisher credentials if compromise is suspected;
6. never clean/reset a worktree as part of rollback;
7. re-enable automation only after the failure is reproduced, fixed, and the pilot gate is green again.

## Test strategy

Automated tests must cover both positive and negative boundaries. At minimum: automatic agent selection cannot return Codex/ChatGPT/Claude; job files reject secrets and unsafe paths; path traversal/symlink escapes fail; stale/mismatched head fails; active leases prevent duplicate execution; failed worker launch releases/requeues safely; timeout kills the process group; logs/results redact known credential formats; worker cannot access production secret files; publisher credentials are absent from worker environment; and legacy worktrees cannot auto-run.

Systemd contract tests assert separate users, expected read/write roots, environment-file separation, hardening directives, service activation wiring, and safe default `auto_dispatch=false` in repository templates.

The existing continuity suite and repository CI remain mandatory. No production enablement is allowed from unit-test success alone; the disposable end-to-end pilot is an independent acceptance gate.

## Acceptance criteria

The architecture is accepted for recurring production use only when all of the following are simultaneously true: exact deployed SHA is known; controller remains read-only; Gemini worker and publisher are isolated identities; credentials are separated and protected; 100% of automatic provider choices are Gemini or a separately certified `rooter`; the pilot interruption/recovery lifecycle passes; required CI passes; provider invocations are attributable to task/lease IDs; no secret appears in job envelopes/logs/commits; and multiple scheduled cycles complete without unexplained AI invocations.

Until those conditions are met, the safe production state is `auto_dispatch=false` with read-only reconciliation continuing every 30 minutes.

## Required migration/documentation updates

The existing continuity runbook still documents the obsolete automatic order `Codex -> ChatGPT -> Claude -> Gemini`. Implementation must replace that statement with the split policy: automatic recurring work is `Gemini -> certified rooter only`, while manually requested interactive agents are governed separately.

The installed production config is operator-owned and intentionally not overwritten by deploy. Enabling automation therefore requires an explicit, idempotent migration/validation step that refuses to enable if forbidden automatic agents remain enabled, worker credentials are missing, or the pilot evidence flag is absent.
