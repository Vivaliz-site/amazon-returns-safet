# Autonomy Cockpit UX and Operational Trust

**Date:** 2026-09-10
**Status:** Approved direction; written specification pending final user review
**Product:** Amazon Returns / SAFE-T
**Scope:** Human-facing cockpit, case consultation, search, return intake and review experience

## 1. Purpose

Make the Amazon Returns cockpit answer, immediately and without technical interpretation:

1. Is the system operating normally?
2. Does anything require the user's action?
3. What money is still at risk, in dispute, awaiting credit or already recovered?
4. What is the system doing automatically right now and what will it do next?
5. Is anything late, inconsistent, unavailable or failing?
6. Why did the system make each decision, and which evidence supports it?

The user should not need to supervise the robot case by case. The interface must make autonomy visible and trustworthy while surfacing only true human decisions as human work.

This design refines, rather than replaces, `2026-09-06-safet-review-cockpit-memory-design.md`. The existing evidence-first, deterministic automation, learned-rule and auditability principles remain authoritative.

## 2. Production UI findings on 2026-09-10

A live inspection of `returns.shopvivaliz.com.br` through the real authenticated UI showed:

- 148 cases, R$ 6,456.00 open and R$ 2,290.36 recovered.
- The top summary reported **0 cases needing the user's attention** while the same screen reported **4 unclassified cases, 1 overdue case without action and 1 unreconciled credit**.
- On a subsequent real UI load, the primary autonomy banner displayed `Resumo operacional indisponível no momento. A lista de casos continua disponível.` while financial cards and cases continued to load.
- Several case rows showed `Aguardando crédito da Amazon` and `saldo ainda a recuperar` while displaying **R$ 0.00** as the outstanding amount.
- Nine financial cards have almost equal visual weight in the first viewport, making it difficult to identify the business-critical numbers at a glance.
- The search hint lists order, NF, SAFE-T, tracking, SKU and ASIN, but omits **TBR**, which is an important return identifier for the operator.
- The current distinction between human work, automatic work and system-health problems is not sufficiently explicit.

These findings are acceptance inputs, not merely cosmetic observations.

## 3. Product principle: separate responsibility from system health

The cockpit must never use one number to imply both `the user has nothing to do` and `the system is healthy`.

Two dimensions are always shown separately:

- **Your action:** whether a genuine business decision, authentication step or user-supplied evidence is required.
- **System health:** whether automatic processing, deadlines, connectors, reconciliation or classification have any unresolved operational problem.

Therefore, `0 need your attention` may coexist with an operational warning, but the screen must explicitly say something equivalent to: `Você não precisa agir. O sistema identificou 2 problemas operacionais e está tentando corrigi-los.` If a problem cannot self-heal and truly requires the user, it moves into the user-action section with a plain-language reason.

## 4. Information architecture

### 4.1 Autonomy and health banner

The first element on the page is a concise status banner with one of three operator-facing states:

- **Operando normalmente** — no human decisions and no overdue/unhealthy automatic work.
- **Sistema requer atenção** — no human decision is needed, but there is a degraded connector, overdue automatic action, unresolved classification or reconciliation problem.
- **Sua intervenção é necessária** — at least one genuinely human action is required.

The banner also shows:

- last successful full processing cycle;
- freshness timestamp (`Dados atualizados às ...`);
- count of cases being treated automatically;
- count of true human actions;
- count of operational problems;
- compact connector health for Amazon/SP-API, Gmail and Seller Central where applicable.

A user must be able to understand the state without reading technical codes, logs or backend enums.

### 4.2 “Precisa de você?”

This section contains only work that cannot safely continue without human input, such as:

- a genuinely ambiguous business decision after deterministic and learned rules are exhausted;
- an authentication action that cannot be renewed automatically;
- evidence or physical information only the user can supply;
- a conflict between learned rules that requires a policy decision.

When empty, it says clearly: **Nenhuma ação sua é necessária.**

Operational defects are not placed here merely because they exist.

### 4.3 “Sistema tratando agora”

Show the highest-value or most urgent automatic work currently underway or scheduled, including:

- current plain-language state;
- next automatic action;
- when it will happen or which external condition is awaited;
- amount still exposed;
- whether the system is waiting for Amazon, waiting for a deadline or retrying an operational action.

The purpose is to show that inactivity in the UI can still represent deliberate autonomous waiting rather than abandonment.

### 4.4 Financial hierarchy

Replace the wall of equally weighted financial cards with four primary business metrics:

1. **Em risco** — total outstanding exposure.
2. **Aguardando crédito** — approved/proactive reimbursement not yet reconciled.
3. **Em disputa** — amount in SAFE-T analysis, appeal or Seller Support.
4. **Recuperado** — reconciled seller credit.

Detailed categories such as denied, appeal, support, eligible-now and loss remain available through progressive disclosure or a secondary breakdown. No information is removed; it is reprioritized.

### 4.5 Operational problems

A dedicated section lists health problems separately from human decisions:

- unclassified cases;
- automatic actions past due;
- credit observed but not reconciled;
- connector/session/read-back degradation;
- repeated write failures or dead-letter conditions;
- stale data beyond the expected update interval.

Each item shows:

- what is wrong in plain Portuguese;
- how many cases or how much money are affected;
- who owns the next step (`Sistema` or `Você`);
- the next automatic retry/action when known;
- how long the condition has existed.

A zero state is visually calm rather than occupying a large warning area.

### 4.6 Deadlines

Show only operationally relevant deadlines, prioritized as:

- overdue;
- today;
- tomorrow;
- next 7 days.

Each deadline must identify the case, amount, action that becomes due and whether execution is automatic or human-dependent.

### 4.7 Case list

Each row should answer the operator’s core questions without opening the case:

- Amazon order number;
- TBR when available;
- SAFE-T ID when available;
- outstanding amount;
- simple current status;
- responsibility: `Sistema` / `Você` / `Concluído`;
- next automatic step and date/condition;
- urgency only when real.

Rows must not display `saldo ainda a recuperar` when `outstanding_amount = 0`.

The default sort prioritizes true human work, overdue automatic work, near deadlines and highest financial exposure before routine waiting cases.

## 5. Case detail: explain, prove, then show history

The detail page/panel follows this order:

### 5.1 What happened

A plain-language narrative combining the relevant order, delivery, refund and return facts.

### 5.2 What the system did

The last meaningful automatic action, including whether it was completed, is queued, is being retried or is awaiting external read-back.

### 5.3 What happens now

The next action or waiting condition, with a date when authoritative. If no user action is needed, say so explicitly.

### 5.4 Important dates

At minimum when available:

- order date;
- customer refund date;
- seller debit date;
- physical return receipt date;
- eligibility date;
- next automatic action date;
- appeal deadline;
- last successful verification.

### 5.5 Values

Show refund amount, recovered amount and outstanding amount with a relationship that is mathematically understandable. A zero outstanding amount cannot be visually described as money still to recover.

### 5.6 Amazon / SAFE-T conversation

Expose the human-readable message thread for the case, including persisted outbound narrative and Amazon responses where available. The user should not need to open Seller Central for ordinary review of what was said.

Messages are grouped chronologically with sender/source, timestamp and type. Technical identifiers are secondary metadata, not the primary text.

### 5.7 Evidence

Show tracking, delivery evidence, NF, TBR, SAFE-T, relevant return facts and financial observations. Evidence used by a decision is visually linked to the explanation of that decision.

### 5.8 “Why did the system decide this?”

A collapsible explanation shows:

- decisive facts;
- applicable business/learned rule in plain language;
- why the selected action follows from those facts;
- any unresolved fact that remains under monitoring.

Backend enum names never appear as the explanation.

### 5.9 Timeline

The full event history remains collapsed by default. Repetitive polling/sync events are condensed into meaningful milestones, while exact audit events remain accessible.

## 6. Search and return intake

### 6.1 Universal case search

Search must accept and visibly advertise:

- Amazon order number;
- sale NF number;
- return TBR;
- SAFE-T ID;
- tracking code;
- SKU;
- ASIN.

Searching an Amazon order must locate related return records even when the primary return identifier is TBR. The same alias/relationship resolution is used in the cockpit and in return intake so users do not experience two different search behaviors.

### 6.2 Register return received

`Registrar devolução recebida` must first locate and preview the matched case before any state-changing save.

The operator can search by order, NF or TBR. The preview must show enough information to confirm the correct return: order, TBR, product, refund/return context and current physical status.

If no case matches, the UI explains which identifiers are accepted and preserves the user's input for retry. It must not silently fail or require knowledge of an internal primary key.

## 7. Review experience

The review screen must feel like a business question, not an internal state machine.

For each review:

- state the exact unresolved question in one sentence;
- show the evidence that supports each plausible interpretation;
- show Amazon/SAFE-T messages inline when they matter;
- show financial exposure and deadline;
- show the AI recommendation in plain language;
- show what the system will learn if the decision is made reusable;
- show affected similar cases before confirmation.

Known deterministic or already learned rules must bypass review entirely. A repeated case that matches an approved rule is a system automation case, not a human review case.

## 8. Zero-outstanding lifecycle normalization

A case with `outstanding_amount = 0` must not show `saldo ainda a recuperar`.

If no unresolved external obligation remains, the case should display a concluded/settled state appropriate to the evidence.

If a final confirmation is genuinely still pending despite zero outstanding amount, use a precise transitional label such as:

**Crédito identificado; aguardando confirmação final.**

The UI must explain the remaining confirmation, rather than suggesting money is still missing.

This normalization may require a projection/state-display correction and must not falsify the underlying audit history.

## 9. Consistent, resilient summary data

The top-level cockpit summary must represent one coherent snapshot, not a mixture of independently timed interpretations that can contradict one another.

The summary contract should expose an `as_of`/freshness timestamp and enough health metadata to derive:

- true human-action count;
- automatic-work count;
- operational-problem count;
- financial headline values;
- connector freshness/health.

If a fresh summary request fails but a recent last-known-good snapshot exists, the UI should preserve that snapshot and label it clearly, for example: `Últimos dados disponíveis, atualizados às 17:42. A atualização mais recente falhou e será tentada novamente.`

Only when no trustworthy snapshot exists should the primary summary be shown as unavailable. `Dados indisponíveis` must never be visually indistinguishable from `zero problems`.

## 10. Interaction and language rules

- One primary question per section.
- Progressive disclosure for secondary technical/audit detail.
- Plain Portuguese in all user-facing labels and errors.
- No raw backend enum, exception code or internal reason code in normal UI.
- Every non-final status explains the next step.
- `Sistema` and `Você` responsibility are explicit and never inferred only from color.
- Red is reserved for genuine urgency/failure, not ordinary waiting.
- Empty healthy states should be compact and reassuring.
- The operator must not need to understand SAFE-T implementation details to use the product safely.

## 11. Notification semantics

Human-review reminders are sent every two hours only while at least one true human review remains open.

Operational faults should use cockpit health and internal alerting/retry mechanisms. They must not be mislabeled as a business decision merely to attract attention.

If an operational fault becomes impossible to self-heal and requires a user action, the system creates a specific user-action item explaining exactly what the user must do.

## 12. Error handling and degraded mode

Errors are categorized for the operator as:

- temporary data refresh problem;
- authentication/connection problem;
- automatic action failure being retried;
- action blocked and requiring user intervention.

The UI should retain safe stale information with freshness labeling when possible, provide a focused retry action, and avoid replacing a useful page with a generic failure state.

A degraded connector must not silently convert to `no pending work`.

## 13. Implementation boundaries

This work may change:

- cockpit PHP/HTML structure;
- cockpit CSS and browser behavior;
- summary/operational projection APIs needed to provide a coherent snapshot;
- search alias resolution for order/NF/TBR/SAFE-T/tracking/SKU/ASIN;
- operator-facing case-state projection and zero-outstanding normalization;
- return-intake search/preview behavior;
- review presentation needed to meet the plain-language/evidence requirements.

This work does **not** authorize unrelated refactoring of the SAFE-T decision engine, credential/TOTP architecture or external write safety model. Existing safety gates, idempotency, tenant isolation and deterministic decision rules remain authoritative.

Concurrent work in authentication/TOTP or other unrelated subsystems must be preserved rather than overwritten.

## 14. Testing and acceptance workflow

Automated tests are necessary during implementation but are not sufficient for completion.

Validation layers are:

1. syntax/lint/static checks;
2. focused tests for each changed component;
3. relevant integration/regression suite;
4. non-destructive functional smoke;
5. **real UI validation in production through the authenticated interface.**

The final real-UI acceptance pass must exercise, using actual rendered screens:

- login/session continuation;
- autonomy/health dashboard;
- universal search by Amazon order;
- search by NF;
- search by TBR;
- case opening and detail readability;
- Amazon/SAFE-T message visibility;
- tracking/evidence visibility;
- order and customer-refund dates;
- financial values and zero-outstanding behavior;
- collapsible timeline;
- `Registrar devolução recebida` search and matched-case preview;
- review queue zero state and, when a legitimate ambiguous case exists, the real review presentation;
- operational problem ownership and next-step explanation;
- consistency between the summary banner and the underlying visible issue sections.

Use real existing cases for UI acceptance. Do not create bogus SAFE-T claims, appeals, Seller Support cases or customer-facing messages merely to test production. A real external write may only occur when it is already a legitimate business action for that real case and all normal safety gates permit it.

Known deterministic cases should be used as read-only acceptance examples where useful, including a delivered-tracking/refunded-customer pattern, to verify that they do not appear as unnecessary human review.

## 15. Acceptance criteria

The implementation is accepted only when all of the following are true:

- Within about 10 seconds of opening the cockpit, the operator can answer `Preciso fazer algo?`, `O sistema está saudável?`, and `Quanto dinheiro ainda está exposto?`.
- `0 precisam da sua atenção` never implies the system is healthy when operational faults exist; the two concepts are shown separately.
- A temporary summary refresh failure does not erase recent trustworthy operational context without a freshness warning.
- No case with R$ 0.00 outstanding is labeled `saldo ainda a recuperar`.
- TBR is both searchable and visibly supported in the search experience.
- Searching by order follows the order-to-return relationship and finds the relevant case when present.
- Case detail shows plain-language reason, evidence, important dates, financial position, Amazon/SAFE-T messages where persisted, next automatic action and responsibility.
- Timeline is collapsed by default and repetitive polling does not dominate it.
- Review contains only genuinely ambiguous unresolved decisions after deterministic and learned rules are evaluated.
- Human reminder semantics remain every two hours only while true reviews are pending.
- No raw backend enum/code is required to understand or operate the normal UI.
- The final production acceptance pass is performed through the real rendered UI, not substituted by script/API assertions.

## 16. Product success condition

A seller should be able to open the cockpit, understand what happened, see what the automation is doing, know whether anything needs personal attention, and trust that routine cases will continue without manual supervision.

The standard is not merely `the backend ran`. The standard is that the product visibly demonstrates correct autonomous operation and makes exceptions immediately understandable.