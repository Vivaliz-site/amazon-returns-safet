# Amazon Returns / SAFE-T operating constraints

## Acesso a infraestrutura e VMs
Antes de executar qualquer comando em VM Oracle Cloud, leia e siga obrigatoriamente `AGENTS-VM-ACCESS.md`. O runbook define as duas VMs atuais, Remote Desktop Commander, SSH administrativo, OCI Run Command via perfil `AGENTS`, a ordem de fallback e as regras para nao expor secrets. Nunca presuma root no OCI Run Command.

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

## Repository completion and deployment rules
- No local modification may be abandoned, left uncommitted, or exist only in a worktree. Every valid change must be committed and pushed; superseded work must be preserved in an explicit branch/commit with its reason documented until it can be safely reconciled.
- Do not finish a task with unresolved merge/cherry-pick state, dirty worktrees, orphan commits, or untracked project artifacts.
- The repository should not be left with pending implementation PRs or pending/failed GitHub Actions. The agent owns validation, remediation, merge, and closure of the change it started.
- Before merge, run the complete project test suite, tenant SQL audit, syntax/lint checks, and diff checks applicable to the touched files. Fix failures immediately and repeat until green.
- Merge only the validated head. Do not merge an older PR head after later fixes have been validated.
- Production deployment is performed by the repository auto-deploy/auto-gate. Do not bypass that gate with an ad-hoc manual production copy except for an explicitly documented emergency rollback.
- After merge, follow the auto-gate until the exact merge SHA is deployed. If deployment fails, investigate and correct it immediately; do not abandon the task at a failed or pending deployment.
- A task is not complete merely because code was merged. Verify deployed SHA, service health, required worker/runtime state, queues, dead letters, and the specific production behavior changed by the task.
- For external-write features, merge/deploy completion and write enablement are separate gates. Keep writes OFF until the relevant production acceptance evidence exists; then enable one channel at a time and verify each canary before advancing.
- When a repository branch/worktree is superseded, reconcile it explicitly: merge/cherry-pick the still-valid commits or preserve it as a clearly named historical branch. Never rely on local-only files as the sole copy of work.

## Owner clarification: D+45 SAFE-T opening blockers (2026-09-05)
For a case where Amazon has refunded the customer, D+45 is the opening trigger. Transport state does not postpone opening. The only business conditions that suppress the new SAFE-T opening are:
1. the seller has already received the full reconciled financial reimbursement; or
2. the seller explicitly confirms through the application intake routine that the physical product arrived.
Carrier tracking, "returning to seller", lost/refused/damaged transport labels, a projected physical status without the seller intake event, a promise of future reimbursement, or a missing separate seller-debit field are not additional blockers once D+45 eligibility is established.
Seller physical arrival is evidenced by the app intake event (`PHYSICAL_RECEIVED`, source `WAREHOUSE`), not by carrier/Seller Central status alone.

## Mandatory delivery rules and persistent project memory
Read `docs/REGRAS-DE-ENTREGA.md` and `docs/MEMORIA-DO-PROJETO.md` at the start of every task.
- The responsible agent owns validation, review, commit/push, merge, auto-gate deployment and functional production verification.
- No task-owned PR, required Action, local-only change, unresolved conflict or failed deployment may be left pending at handoff.
- Do not merge obsolete/conflicting code merely to clear a checklist; prove the valid requirements were absorbed by the replacement.
- Deploy only through `scripts/auto-deploy.sh` via `amazon-returns-deploy.service`/timer. `dirty_checkout`, `ci_not_green` and `already_current` are states to investigate, not proof of delivery.
- If tests, CI or deploy fail, correct the cause and repeat the gate in the same execution. External writes still require their channel-specific production acceptance.


## Owner clarification: damaged returns (2026-09-05)
- Initial SAFE-T opening for physically damaged/discrepant returns is manual-only by the user; the app must not auto-submit a new damaged-return claim.
- After the user has manually opened the claim and a SAFE-T ID exists, the app may continue the denial/appeal lifecycle if Amazon denies it, subject to official deadlines and normal evidence/deduplication gates.
