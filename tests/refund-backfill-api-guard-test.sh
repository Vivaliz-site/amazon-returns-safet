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

echo 'refund-backfill-api-guard-test: OK'
