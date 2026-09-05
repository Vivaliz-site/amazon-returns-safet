# Amazon Returns / SAFE-T operating constraints

## Latest owner decision: 2026-09-05
First operational opening for ShopVivaliz is D+45. Do not silently replace this with D+60 or D+75.
When Amazon requests a wait, preserve its actual requested date and response evidence; resume/reopen in the correct existing channel on that date, after real financial revalidation.
This supersedes older D+75 notes and the abandoned45/60 FIRST-opening proposal. Published Amazon program-specific guidance is still external evidence, not rewritten by this operational instruction. Respect the live Amazon eligibility check.
See `docs/runbooks/shopvivaliz-d45-operational-policy.md` and `docs/superpowers/plans/2026-09-05-d45-dated-resume.md`.

## Invariants
- Scope policies, cases, events, queues and cursors by tenant/connection. Never propagate the ShopVivaliz override to another seller without approval.
- Keep policy history; activate a new immutable version instead of rewriting historical rule values.
- Never infer a resumption date from a historical refund date or quoted correspondence. Ambiguous dates need review.
- One dated attempt per original Amazon instruction/date. Reuse existing claims, email threads and support cases; do not generate duplicates when an appeal window expires.
- Approval, a promise, a rejection, successful submission or elapsed time is not recovered money. Require actual financial reconciliation before RECOVERED.
- External writes remain subject to independent channel gates and real production acceptance. Do not enable them just because unit tests or health checks pass.
- Legacy runtime remains disabled but preserved until complete isolated-service acceptance.
- Use isolated worktrees, regression-first tests, independent review, CI and verified deployment SHA. Preserve unrelated/uncommitted work.
- Do not bypass authentication, Amazon eligibility, tool permissions or OS privileges. Never log credentials or arbitrary message bodies.
