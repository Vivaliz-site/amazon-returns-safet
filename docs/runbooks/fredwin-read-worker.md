# Fred-Win SAFE-T reader: single-instance recovery

## Scope
Prevent duplicate readers from claiming jobs and navigating the same CDP page.
No policy, database schema, financial state, or external write flag is changed.
D+75 remains unchanged. A SAFE-T approval is not proof of reconciled credit.

## Process-owned lease
The reader reserves 127.0.0.1:19225 before its first pull or CDP operation.
SELLER_CENTRAL_STATUS_LOCK_PORT may select a different reserved local port.
All runners for the same browser must use the same lease port.
A duplicate logs worker_already_running and exits without claiming a job.
The lease is released by node process exit, including an orphaned runner's child.
The --heartbeat operation does not need the browser lease.
The --once operation does need it and will not run beside the daemon.
This does not serialize a separate write worker: keep writes OFF until that channel is audited.

## Verification
Run php tests/amazon-returns-read-worker-test.php and the full PHP suite.
The regression starts two real node workers against a loopback NO_JOB service.
Before this fix both claimed work; after it only the owner can pull.
It also verifies heartbeat access, lease recovery on exit, and CDP close/error rejection.

## Live recovery evidence (2026-09-05 UTC)
After removing a duplicate, the 06:45:13-06:46:34 batch completed 13/13 reads.
Results: 8 DENIED, 5 APPROVED, 0 UNKNOWN, 0 unmatched read jobs.
These observations do not establish financial credit or complete channel acceptance.
The live case count is 39, versus the earlier 37-case migration snapshot; audit remains required.
Preserve the disabled legacy runtime until full production acceptance.
