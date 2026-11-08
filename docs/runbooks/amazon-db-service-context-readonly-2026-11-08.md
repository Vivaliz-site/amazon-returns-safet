# Amazon Returns MySQL preflight — identity-aware, read-only (08/11/2026)

## Correct production read path

Use the existing app's [`api/health.php`](../../api/health.php) through
its normal authenticated/configured web runtime. Its PHP entrypoint calls
`amazon_returns_require_pdo()`, resolves the current tenant/connection with
`SvAmazonTenantRegistry::resolveCurrent`, constructs the scoped persistence,
then reads `SvAmazonReturnsRuntime::health`. An HTTP 200 with `status=DEGRADED`
**proves this code path reached the database**, not that all returns, external
permissions or SAFE-T claims are healthy. A PDO/config/tenant exception returns
a generic HTTP 503 `FAILED`.

The web/daemon service runs with the existing `www-data` principal. The
protected private environment at
`/home/ubuntu/amazon-returns-deploy/shared/.env` is not readable by the
ordinary `ubuntu` MCP command principal. **Do not copy, print, broaden,
chmod, sudo around, or extract this file.** Running
`amazon_returns_require_pdo()` in an unconfigured `ubuntu` PHP process
does not test production MySQL availability.

For troubleshooting without a service-level grant, the safe preflight is:

```sh
curl -fsS --max-time 8 https://returns.shopvivaliz.com.br/api/health.php
```

Parse only `status`, blocker names and HTTP code. Keep authentication,
logging and private values intact. Use the existing authorized application
service or an independently authorized and fenced service operator for
individual case SELECT queries. These must be prepared, tenant- and
connection-scoped, `START TRANSACTION READ ONLY` and `ROLLBACK`.
A public health check intentionally does **not** disclose order IDs,
customer details, financial data or case-by-case proof.

## Regression and diagnosis

The PHP database module now emits private
`[amazon-returns-db] DB_RUNTIME_CONTEXT_MISSING` **only** when the
configured DB user/name are unavailable *before any connection attempt*.
A genuine failed PDO `SELECT 1` retains the previous private
`connection unavailable` log. The public exception remains generic
(`Amazon Returns database unavailable.`) to prevent accidental disclosure.
No external write gates, grants, service identities, MySQL permissions,
secrets or scheduler were changed.

RED: test `tests/db-runtime-context-diagnostics-test.php` failed under
an intentionally empty synthetic environment (MCP task
`7d66515e-1ebf-42d9-becd-278348f74392`, exit 255, expected).
GREEN: the new diagnostic and five existing public/operational health tests
passed with PHP lint and diff validation, isolated backend task
`7906ea4e-4f9d-43d2-aa2d-0ab779898404` exit 0, head
`23cde344e26df06b24dd78cf7cde863efcbd10e8`.
The immutable Marina release and normal health endpoint must be reread
**after** official protected merge/deploy before claiming production coverage.

## Outstanding SAFE-T scope

A healthy MySQL does not prove external claim absence, Amazon-initiated
refund, physical nonreceipt or uncompensated debit. Case #8803 already has
an external SAFE-T claim; #42471 is not under owner grant #3556.
Existing global write kill switch and all seven external write gates
remain unchanged. Do not run a second browser/worker or bypass the service
principal, MFA, RBAC, grant expiry, lease/fencing or financial readback.
