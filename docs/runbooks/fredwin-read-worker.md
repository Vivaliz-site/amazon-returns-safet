> Superseded rule clarification (2026-09-05): the owner requires FIRST opening at D+45. Later waits follow the exact date requested by Amazon, not a new global 60/75-day threshold. See `docs/runbooks/shopvivaliz-d45-operational-policy.md`.

# Fred-Win SAFE-T browser dispatcher: transient execution

## Scope
Seller Central browser automation on Fred-Win is a fallback for operations not covered by SP-API/Gmail/API. Routine monitoring and financial reconciliation remain API-first. The Fred-Win browser workers must not stay resident when there is no Seller Central job.

## Runtime model
A single scheduled task, `ShopVivaliz Amazon Returns Browser Dispatcher`, runs at a short recurring interval. Each invocation is finite:

1. run the read worker with `--once`;
2. run the write worker with `--once`;
3. if a job actually needs Seller Central, the dedicated Opera profile on CDP port 9225 is opened by the worker;
4. after the invocation, terminate only the dedicated Seller Central Opera tree for port 9225/profile;
5. exit, leaving no persistent Node or dedicated Opera process.

The legacy tasks `ShopVivaliz Amazon Returns SAFE-T Read Bridge` and `ShopVivaliz Amazon Returns Seller Central Bridge` are removed by the installer. The dispatcher uses `MultipleInstances IgnoreNew`, so read/write work is serialized on the shared profile.

## API-first boundary
SP-API, Finances, Returns and Gmail remain the primary sources. Seller Central UI is used only for jobs that require browser-only read/write behavior. External writes still obey server-side channel gates, idempotency, eligibility and production acceptance rules.

## Verification
Run:

- `php tests/windows-bridge-on-demand-install-test.php`
- `php tests/windows-bridge-tracking-helper-install-test.php`
- `php tests/amazon-returns-read-worker-test.php`
- `php tests/amazon-returns-remote-bridge-test.php`
- full PHP suite and syntax checks required by `docs/REGRAS-DE-ENTREGA.md`

On Fred-Win, verify that the dispatcher returns to `Ready`, no legacy Amazon bridge task remains, no bridge `node.exe` remains after the invocation, and no Opera process using the dedicated port 9225/profile remains when the queue is idle.
