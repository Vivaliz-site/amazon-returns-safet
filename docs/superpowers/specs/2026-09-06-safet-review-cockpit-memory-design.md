# SAFE-T Review Cockpit and Learned Decision Memory

**Date:** 2026-09-06
**Status:** Approved design; implementation plan: `docs/superpowers/plans/2026-09-06-safet-review-cockpit-memory.md`
**Product:** Amazon Returns / SAFE-T
**Tenant initially enabled:** ShopVivaliz

## 1. Purpose

Build a single operational cockpit where the user can see every Amazon return / SAFE-T case, inspect its full history, review ambiguous cases with an AI recommendation, approve or change that recommendation, and persist the final human decision as reusable memory.

A decision approved in review must immediately become reusable for materially similar existing and future cases. The system must learn the reasoning pattern, not copy case-specific literals such as order IDs, amounts, or one-off dates.

The cockpit is the authoritative human-facing view for SAFE-T operations. It must explain what happened, what was written externally, what Amazon returned, why the system proposes the next action, and which learned rule caused any automatic decision.

## 2. Core principles

1. **Human-approved learning.** AI may suggest; the user's approved or edited decision is authoritative.
2. **Deterministic execution.** Learned decisions become structured, versioned rules evaluated by the deterministic engine.
3. **Known rules run automatically.** An already-defined business rule or active learned rule does not require another human approval; matching existing and future cases continue through the normal deterministic flow automatically.
4. **Existing + future propagation.** Rule promotion immediately re-evaluates all current matching cases and governs future matching cases.
5. **Evidence first.** Every recommendation and rule application must reference the facts/evidence used.
6. **Full auditability.** Every review, rule version, application, external write, read-back, status observation and financial observation remains traceable.
7. **Fail closed.** Missing evidence, conflicting rules or unsafe ambiguity returns the case to review instead of guessing.
8. **Safety gates always win.** Learned memory cannot bypass hard business or write-safety gates.
## 3. Cockpit information architecture

The admin area becomes one operational workspace with three primary views.

### 3.1 All cases / SAFE-T

Show every current and historical case, not only recent items. Each row/card must expose at least:

- Amazon order ID, item/SKU/ASIN and program;
- SAFE-T ID and support case ID when present;
- current case state and physical status;
- refund date, eligibility date, next action date and appeal deadline;
- expected reimbursement, reconciled credit and outstanding amount;
- current recommended/required action;
- review state and learned-rule indicator;
- last external write and last Amazon/read-back observation;
- last update timestamp.

Filters must support order ID, SAFE-T ID, state, action, review status, program, physical status, deadline, monetary exposure and learned-rule status. Default sort prioritizes overdue/urgent review work, then upcoming deadlines, then newest activity.

### 3.2 Review queue

A dedicated queue contains only cases that remain unresolved after evaluating hard business rules and active learned memory: `HUMAN_REVIEW`, review-gated `BLOCKED_REVIEW`, conflicting learned rules, low-confidence AI suggestions and any case explicitly sent back to review. A case fully matched by an existing deterministic rule or active learned rule must bypass the queue and continue automatically.

Each review card must show enough context to decide without navigating to separate systems for ordinary cases. A user can still open the full case detail before deciding.

### 3.3 Learned rules / memory

A memory view lists active, superseded and disabled learned rules, their origin review, match conditions, prescribed action, version, application count, outcomes, exceptions and last modification.
## 4. Case detail: 360-degree SAFE-T history

Opening a case must show one chronological, evidence-backed timeline combining all channels relevant to that case.

The timeline includes:

- order/refund/return observations;
- return-report and physical-receipt observations;
- policy eligibility changes;
- SAFE-T creation and registration;
- SAFE-T status reads;
- every claim and appeal narrative submitted;
- every Seller Central write result and external ID/read-back;
- Amazon denial/approval/info-request messages;
- Gmail messages correlated to the case;
- Seller Support creation/update events when that channel is enabled;
- financial transactions, reimbursement observations and reconciliation checks;
- AI review recommendations;
- human review decisions;
- learned-rule creation, supersession and application;
- errors, retries and dead-letter outcomes where applicable.

The detail view must distinguish **observation**, **decision**, **external write**, **Amazon response**, **financial event** and **learned-rule application**. External writings must display the exact persisted narrative sent by the app together with timestamp, action type, idempotency key reference, result status and read-back evidence. Secrets/tokens are never displayed.

For one SAFE-T, the user must be able to answer from this page alone: what we knew, what we decided, what we sent, what Amazon showed afterward, what money was recovered, and what action is next.
## 5. Review workflow with AI suggestion

When a case remains unresolved after all applicable deterministic business rules and active learned rules have been evaluated, the system builds a normalized ReviewContext from deterministic facts already persisted for the case. The AI receives only the minimum context required to reason about the novel or ambiguous case and returns a structured suggestion; it never performs an external write. Existing approved logic is not re-sent for human approval.

The review panel displays:

- why review was required;
- hard facts and unresolved facts;
- relevant Amazon text/evidence excerpts and timestamps;
- current financial position;
- deadlines and timing constraints;
- the AI-recommended action;
- explanation/rationale;
- confidence and identified uncertainty;
- exact proposed parameters, such as a promised date to preserve or action scope;
- predicted impact: matching existing cases and future applicability.

Available human actions are:

1. **Approve suggestion** — accept the recommendation as written.
2. **Edit and approve** — change action, parameters or rationale, then approve the edited decision.
3. **Reject / choose another action** — record a different final disposition.
4. **Wait** — preserve a specific date/condition and return the case to deterministic scheduling.
5. **Exception only** — apply the decision to this case without promoting a reusable rule.

Before a reusable approval is confirmed, the UI must show the deterministic similarity signature and the exact existing cases that would be re-evaluated. Approval is explicit and CSRF-protected.

If the AI service is unavailable or produces invalid/unsupported output, the review still works as a human-only review; no automatic write occurs and no learned rule is created until a valid human decision is saved.
## 6. Decision memory model

Human approval creates an immutable DecisionRecord and, unless marked `Exception only`, promotes or versions a LearnedRule immediately.

### 6.1 DecisionRecord

Persist at minimum:

- tenant/connection and case identifiers;
- originating review reason;
- normalized facts/signature used for similarity;
- AI suggestion payload and model/provider metadata when AI was used;
- human final action and parameters;
- human rationale/comment;
- whether the human approved unchanged or edited the suggestion;
- evidence/event references used for the decision;
- affected-case preview captured at confirmation time;
- actor, timestamps and source UI/API version;
- later observed outcome when known.

The AI proposal remains preserved for audit even when the human changes it. Only the final human decision can teach an active rule.

### 6.2 LearnedRule

A learned rule stores generalized conditions plus deterministic effects. It must not store case-specific literals as matching requirements unless the literal is itself a business category (for example marketplace or program).

Rules are tenant-scoped by default. A future commercial/global promotion workflow may copy validated tenant rules into a separately governed global rule set; this design does not silently share ShopVivaliz decisions with other tenants.

Learned rules are versioned and immutable after activation. A change creates a new version and marks the old version superseded. Rules can also be disabled without deleting their history.
## 7. Deterministic similarity and rule matching

Similarity must not be decided by free-form semantic AI at execution time. The system computes a canonical signature from structured facts. Initial dimensions are:

- review reason / ambiguity class;
- marketplace and fulfillment/return program;
- SAFE-T lifecycle stage (none, submitted, denied, appeal submitted, approved, credit pending, terminal);
- physical-return category;
- refund initiator category and whether refund date is authoritative;
- financial posture (no credit, partial credit, full credit, amount unresolved);
- evidence type/pattern that triggered the review;
- presence/type of Amazon promised date or requested wait condition;
- appeal-window posture;
- whether the case is damaged/discrepant and whether the initial manual opening already exists.

Raw order IDs, SAFE-T IDs, one-off monetary amounts, exact promised dates and customer-specific text are excluded from the equality signature. Rule effects may reference extracted variables, for example `WAIT_UNTIL(promised_date)` followed by `CHECK_FINANCES` and the approved recovery action if still unrecovered.

A rule matches only when all required dimensions and evidence predicates match. Extra materially relevant facts that contradict the originating decision block the rule and send the case to review.

### 7.1 Conflict resolution

- Hard safety/business gates are evaluated first and cannot be overridden.
- A more specific active learned rule may refine a broader learned rule only when their effects are compatible.
- Two active matching learned rules with incompatible effects produce `HUMAN_REVIEW` with reason `LEARNED_RULE_CONFLICT`.
- A disabled/superseded rule never executes but remains visible in history.
- Every match records the rule ID/version and input signature used.
## 8. Propagation to existing and future cases

Immediately after a reusable decision is approved:

1. Save the DecisionRecord transactionally.
2. Create/activate the LearnedRule version.
3. Find all current tenant cases whose canonical signature matches.
4. Re-evaluate each match through the normal deterministic decision engine.
5. Record `LEARNED_RULE_APPLIED` for every affected case.
6. Queue only actions that pass the existing write profile, eligibility, deadline, idempotency and read-back gates.
7. Show the review outcome with counts of re-evaluated, changed, unchanged, blocked and queued cases.

This propagation is not a bulk blind write. It is a bulk **re-decision**; each case independently passes the same safety checks it would have passed without learned memory.

For both existing and future cases, hard business rules and active learned rules are evaluated before emitting a review request. A fully matching safe rule continues through scheduling and execution automatically without new human approval. A partial match, conflict or new material fact creates review.

The rule engine must be replayable against historical facts so a rule can be regression-tested before and after later version changes.

## 9. Mandatory gates learned memory cannot bypass

At minimum, the following remain authoritative:

- ShopVivaliz D+45 operational opening policy is based on Amazon customer `refund_at`.
- Full seller credit reconciliation prevents redundant recovery writes.
- Authoritative seller physical receipt may suppress a not-received initial claim when applicable.
- Damaged/discrepant returns keep their **first SAFE-T opening manual**; automation may act only after the manual claim exists and subsequent eligibility is established.
- Amazon-requested wait dates are preserved exactly; dates are never guessed.
- Official appeal deadlines are never invented or extended by memory.
- External writes require the enabled versioned write profile, idempotency and successful read-back confirmation.
- Generic historical denial text cannot rewind an already submitted appeal without a newer explicit outcome.
## 10. Data model additions

Keep `amazon_return_overrides` as immutable audit of direct case-level changes. Add purpose-built tenant-scoped tables for review and learned memory.

### 10.1 `amazon_return_reviews`

One row per review episode, containing case/tenant/connection IDs, review reason, status, normalized context hash, AI suggestion JSON, AI metadata, human decision JSON, actor, decision mode (`APPROVED`, `EDITED_APPROVED`, `REJECTED`, `EXCEPTION`), created/decided timestamps and resulting rule version ID when promoted.

### 10.2 `amazon_return_learned_rules`

Versioned rule definition with tenant/connection scope, stable rule family key, version, status, match signature/predicates JSON, effect/action JSON, source review/decision ID, priority/specificity metadata, activation/supersession timestamps and outcome counters.

### 10.3 `amazon_return_rule_applications`

Audit row for every evaluated application that changes or confirms a decision: rule version, case, input signature hash, result, blockers, emitted action reference and timestamp.

### 10.4 Evidence and writes

Do not duplicate evidence blobs or narratives unnecessarily. Reviews and rule applications reference existing event/evidence/outbox IDs. Exact write narratives are projected from the immutable event/outbox/action evidence that actually exists. Historical text that was never persisted must be shown as unavailable rather than reconstructed or invented. From this feature onward, every new external write must preserve an immutable exact narrative snapshot together with its idempotency/action/read-back references.

All new tables must preserve the repository's tenant + Amazon connection ownership constraints and isolation tests.

## 11. AI contract

AI is an optional recommendation adapter behind a strict schema. It returns only supported action categories and structured parameters. Free-form rationale is explanatory, not executable.

The deterministic server validates the AI response against allowed enums, case facts and hard gates before showing it as actionable. The AI has no credential or direct Seller Central/Gmail execution path. Provider failure, timeout, malformed JSON or unsupported action degrades to manual review without changing case state.
## 12. Admin APIs and UI behavior

Extend the existing authenticated admin surface rather than creating a second administration stack.

Required server capabilities:

- paginated/filterable case/SAFE-T list;
- complete case detail/timeline projection;
- review queue and single review detail;
- request/refresh AI suggestion;
- preview deterministic rule signature and affected existing cases;
- submit human review decision;
- transactional rule promotion/versioning;
- re-evaluate matching existing cases;
- list/view/disable learned rules;
- expose exact persisted external-write history and read-back status.

Mutating review/rule endpoints require authenticated admin role, CSRF protection, tenant binding, optimistic concurrency/version checking and idempotent decision submission. A stale browser tab must not silently overwrite a newer review/rule decision.

The UI must be responsive for iPhone use. Dense histories may use collapsible sections, but critical facts, deadline, recommended action and approval controls must remain readable without horizontal page scrolling.

No review button directly writes to Amazon. Review approval changes deterministic state/rules; only the normal scheduler/outbox/bridge pipeline may later execute an eligible external write.

## 13. Audit, observability and explainability

For any automated action the cockpit must be able to render a human-readable explanation chain:

`observed facts -> hard gates -> learned rule (if any) -> deterministic decision -> queued external action -> bridge result -> read-back -> later Amazon/financial outcome`.

Operational health must expose counts for pending reviews, learned-rule conflicts, AI suggestion failures, rule applications, review-triggered re-evaluations, pending/processing outbox, dead letters and cases eligible without action.
## 14. Security and failure behavior

- Tenant/connection ownership is resolved server-side; client-supplied tenant IDs are not trusted.
- AI payloads exclude credentials, bridge tokens and unrelated customer data.
- Human decision writes are append-only/audited; rule supersession never erases prior decisions.
- A database or AI failure cannot partially promote a rule. Decision + rule activation use a transaction boundary.
- Re-evaluation failures are isolated per case and surfaced in the review result; one bad case cannot abort unrelated matches.
- External actions remain idempotent and are never executed inside the HTTP review request.
- Existing global kill switch and write-profile flags retain precedence over learned rules.

## 15. Rollout strategy

1. Add schema/repositories and replay-safe rule evaluator with writes still governed by existing profile.
2. Add read-only cockpit views for all cases, SAFE-T timeline and external writes.
3. Add review queue and human decision persistence without rule propagation.
4. Add AI suggestion adapter in recommendation-only mode.
5. Enable learned-rule preview and historical replay tests.
6. Enable promotion after explicit human approval, initially for ShopVivaliz only.
7. Enable immediate re-evaluation of matching existing cases.
8. Validate that future matching cases bypass redundant review while conflicting/new situations still stop for review.

Each rollout stage must be deployable through the existing CI + auto-gate and must not require enabling email/support writes.

## 16. Testing requirements

Tests must cover tenant isolation, review authorization/CSRF, AI schema validation, edited-vs-approved decisions, exception-only decisions, canonical signatures, rule conflicts, rule supersession, existing-case re-evaluation, future-case reuse, hard-gate precedence, idempotent review submission, timeline ordering and exact write-history projection.

A production shadow/replay audit must run the learned-rule engine against all current cases before enabling automatic rule application. The audit must prove which cases would change and that no hard gate is bypassed.
## 17. Acceptance criteria

The feature is complete only when all of the following are demonstrated end to end:

1. Admin can list **all** tenant cases/SAFE-T records and filter/search them.
2. Opening a SAFE-T shows the complete chronological history, including every persisted external narrative and read-back/result.
3. Every pending review shows the reason, evidence, deadlines, financial context and an AI suggestion when AI is available.
4. User can approve, edit-and-approve, choose a different decision or mark case-only exception.
5. Approved reusable decisions create a versioned learned rule with immutable origin/audit data.
6. Before confirmation, UI shows the exact existing cases matching the proposed rule signature.
7. After approval, all matching existing cases are re-evaluated and per-case results are visible.
8. Existing and future cases that match an already-defined business rule or active learned rule proceed automatically without redundant human approval.
9. A materially different fact or incompatible learned rule returns the case to review instead of guessing.
10. Hard gates remain authoritative, including damaged-return initial manual opening and exact Amazon wait/deadline handling.
11. Rule application never directly performs an external write; writes still traverse scheduler/outbox/bridge/idempotency/read-back.
12. Every automatic decision can be traced back to facts, rule version and originating human decision.
13. AI failure leaves a usable manual-review path and cannot block the core recovery system.
14. Existing production write flags, kill switch, pending-outbox and dead-letter safety behavior remain intact.
15. CI, tenant SQL audit, replay/shadow validation and production smoke tests are green before enabling learned-rule execution.

## 18. Example learned decision

Observed case: Amazon states that no action is necessary because it will issue a proactive reimbursement by a specific date; seller credit is not yet fully reconciled.

AI suggestion may propose: preserve Amazon's promised date, wait until that date, re-check finances, and if still unrecovered proceed through the approved SAFE-T recovery path.

If the user approves, the reusable memory does **not** store the example date as the rule. It stores the pattern: `PROMISED_ACTION_WITH_EXPLICIT_DATE + OUTSTANDING_CREDIT -> WAIT_UNTIL(extracted_promised_date) -> RECHECK_FINANCES -> approved recovery action if still outstanding`, subject to all hard gates.
## 19. Non-goals for this phase

- Learned rules are not automatically shared across tenants.
- AI is not allowed to execute Seller Central/Gmail writes directly.
- This phase does not enable automatic initial SAFE-T opening for damaged/discrepant returns.
- This phase does not enable email review/reply or Seller Support writes; those retain their independent activation gates.
- The cockpit is not a generic Amazon Seller Central replacement; it is focused on returns, SAFE-T, recovery actions, evidence and financial reconciliation.
- Outcome-based automatic rule mutation is not enabled initially. Outcomes are recorded so a later governed workflow can propose rule refinement or retirement.

## 20. Design decision summary

The approved architecture is **AI as advisor/teacher + human approval + deterministic learned rules + existing/future replay**.

The cockpit becomes the single operational surface for reviewing cases and understanding every SAFE-T action. The current deterministic safety engine remains the final authority for external execution. Human-approved memory reduces repeated reviews without converting probabilistic AI output into an unchecked write path.
