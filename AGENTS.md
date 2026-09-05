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

## Mandatory delivery rules and persistent project memory
Owner instructions recorded on 2026-09-05. Read these files at the start of every task:
- `docs/REGRAS-DE-ENTREGA.md`: mandatory execution, review, integration and deployment gates.
- `docs/MEMORIA-DO-PROJETO.md`: dated owner decisions and durable project context, not live status.

A task is not complete at diagnosis, local edit, commit, push, PR creation, CI success or merge alone.
The responsible agent must validate the complete change, obtain the required review, commit and push all in-scope source/docs/tests, merge the reviewed SHA, let the existing auto gate deploy, and verify the actual production release and functional evidence.
Do not leave task-owned changes local, in an uncommitted worktree/stash, in an unpushed commit, or in an abandoned open/draft PR. Inventory all repository worktrees and give every discovered change an explicit disposition without overwriting concurrent work.
Do not merge obsolete/conflicting code merely to clear a checklist. Preserve its history, identify the reviewed replacement and account for every applicable requirement before closing a superseded PR.
No task-owned PR or required Action may be left pending at handoff. Inspect repository-wide pending work and separate active concurrent work from abandoned work; never cancel valid checks or hide failures to obtain a green dashboard.
If tests, CI or the auto-gated deploy fail, investigate, correct, retest and follow the gate through a successful release in the current execution. A genuinely external block must be evidenced and reported as blocked, never complete; retain a committed, safely resumable checkpoint.
Deployment must use `scripts/auto-deploy.sh` through the installed `amazon-returns-deploy.service` / timer. Do not force a release symlink, bypass required checks or run an ad hoc replacement deployment to claim success.
Record commit/merge/deployed SHAs, required CI results, validation commands, production timestamp and remaining business-acceptance blockers. Treat `already_current`, `ci_not_green` and `dirty_checkout` as conditions to verify, not deployment success by themselves.
These delivery requirements do not authorize exposing secrets, changing unrelated repositories, bypassing permissions or enabling external writes without channel-specific production acceptance.
