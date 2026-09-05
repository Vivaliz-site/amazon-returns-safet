# D+45 opening and Amazon-requested resumption

**Goal:** Apply the owner's latest explicit instruction: first operational opening at D+45; resume on the date Amazon explicitly requests, never silently replace first opening with D+60 or D+75.
**Architecture:** Tenant-scoped versioned operational policy; separate published Amazon guidance; evidence-backed date extraction and idempotent lifecycle resumption. Existing read, credit reconciliation, submission preflight and channel write gates remain mandatory.
**Stack:** PHP, MySQL scoped repositories, Node Windows CDP reader, GitHub CI and existing deployment timer.
**Spec:** This plan supersedes earlier D+75 and 45/60-first-opening plans for the ShopVivaliz tenant only. Published Amazon 60-day guidance is retained as external evidence, not rewritten as 45 days.

## Global constraints
- Do not bypass Amazon eligibility, CAPTCHA, MFA, tool authorization or OS permissions.
- Do not create duplicate SAFE-T/support cases; continue existing claim/thread/case when possible.
- No recovery closure from approval, denial, a promise, or a date; financial credit must be real and reconciled.
- Distinguish failed delivery/lost/damage/FBA support routes from seller return-not-received.
- Missing/ambiguous dates or expired appeal window require review, not an invented deadline.
- No external write flag is changed by this implementation.

## Execution ledger
- [x] Read live code, existing D45 draft, public Amazon source, existing reply/status and outbox contracts.
- [ ] Tests: first opening D44/D45 for supported programs; immutable tenant policy activation; foreign tenant unchanged.
- [ ] Implement PolicySeeder/PolicyRepository and exact operational-policy verifier, retire legacy active D75 without deleting historical rows.
- [ ] Tests: explicit date in Amazon status/mail; future WAIT; due date triggers finance check then existing-channel follow-up; credit suppresses reopening; same date is idempotent; missing date does not guess.
- [ ] Implement date parser and decision/scheduler persistence of next_action_at; maintain canonical source evidence.
- [ ] Update runbooks/specs/audits to distinguish D45 operational opening from external policy and remove active D75 invariant.
- [ ] Run full suite, SQL isolation audit, PHP/Node/Bash lint, independent review, CI and merge.
- [ ] Observe existing deployment timer; verify active policy and per-case decisions in private journal; verify no stale PROCESSING and writes OFF.
- [ ] Retain disabled legacy runtime until the complete production acceptance gates pass.
