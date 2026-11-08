#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
script="$root/scripts/amazon-returns/refund-backfill-api.php"
export AMAZON_RETURNS_RELEASE_ROOT="$root"

php -l "$script" >/dev/null

assert_rejected() {
  local label="$1"
  shift
  if php "$script" "$@" >/dev/null 2>&1; then
    printf 'FAIL: %s was accepted\n' "$label" >&2
    exit 1
  fi
}

# These must reject BEFORE opening any DB or issuing an Amazon request.
assert_rejected "window zero" --days=0
assert_rejected "window beyond max" --days=91
assert_rejected "apply without a signed preflight set" --apply
assert_rejected "apply with malformed hash" --apply --expected-hash=not-a-hash

# Prevent the one-off repair from accepting conflicting ORDER_IDs in one refund.
# The daemon's shared scanner rejects malformed/conflicting identifiers.
if ! grep -Fq 'SvAmazonRefundDiscovery::scan($transport, $days, $now)' "$script"; then
  printf 'FAIL: refund backfill does not use canonical ambiguity-safe scanner\n' >&2
  exit 1
fi
php "$root/tests/refund-discovery-daily-test.php" >/dev/null

echo 'refund-backfill-api-guard-test: OK'
