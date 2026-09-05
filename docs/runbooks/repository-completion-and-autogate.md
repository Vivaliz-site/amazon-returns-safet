# Repository completion, merge, and auto-gate rules

These rules apply to every Amazon Returns / SAFE-T change.

## Definition of done
A change is complete only when all of the following are true:
1. No valid implementation remains only as an uncommitted/untracked local change.
2. All required changes are committed and pushed.
3. The full relevant test/lint/audit suite is green on the exact head to be merged.
4. The validated head is merged; no older head is substituted.
5. Related implementation PRs and CI runs are no longer pending or failed.
6. The repository auto-gate deploys the exact merge SHA.
7. Deployment is verified in production: SHA, service health, queues/dead letters, workers and changed behavior.
8. Any deploy failure is fixed immediately and the gate is rerun until successful.

## Local work and worktrees
- Dirty worktrees are not an acceptable terminal state.
- Valid work is committed and pushed.
- Superseded/conflicting work is either reconciled into the active branch or preserved in a named branch with a documented reason.
- Untracked project artifacts must be committed to the appropriate documentation branch/path or deliberately moved outside the repository; they must not be silently forgotten.
- Merge/cherry-pick conflicts must be resolved or the in-progress operation cleanly preserved and documented before the agent stops.

## Pull requests and Actions
- The agent that starts a repository change is responsible for its PR lifecycle: validation, review findings, fixes, merge and post-merge verification.
- Do not leave implementation PRs or required Actions pending/failed at task completion.
- If CI or review reveals an error, correct it immediately, push the fix and re-run the checks.

## Deployment
Production deploys through the repository auto-deploy/auto-gate. The agent must follow the gate through completion and verify the deployed SHA. Manual production copying is not a substitute for a successful gate.

## External writes
A successful deploy does not automatically authorize Amazon/Gmail/Support writes. Those channels retain independent acceptance gates. Enable one at a time only after production evidence supports it, and validate a real canary before proceeding to the next channel.
