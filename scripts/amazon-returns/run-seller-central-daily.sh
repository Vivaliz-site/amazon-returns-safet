#!/usr/bin/env bash
set -euo pipefail

ROOT="${AMAZON_RETURNS_RELEASE_ROOT:-/home/ubuntu/amazon-returns-deploy/current}"
: "${SELLER_CENTRAL_BROWSER:?SELLER_CENTRAL_BROWSER is required}"
: "${SELLER_CENTRAL_PROFILE:?SELLER_CENTRAL_PROFILE is required}"
: "${SELLER_CENTRAL_BRIDGE_TOKEN_FILE:?SELLER_CENTRAL_BRIDGE_TOKEN_FILE is required}"

CDP_PORT="${SELLER_CENTRAL_CDP_PORT:-9225}"
CDP_URL="${SELLER_CENTRAL_CDP_URL:-http://127.0.0.1:${CDP_PORT}}"
browser_pid=""

cleanup() {
  if [[ -n "$browser_pid" ]] && kill -0 "$browser_pid" 2>/dev/null; then
    kill "$browser_pid" 2>/dev/null || true
    wait "$browser_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

mkdir -p "$SELLER_CENTRAL_PROFILE"

if curl -fsS --max-time 2 "$CDP_URL/json/version" >/dev/null 2>&1; then
  echo "PREEXISTING_CDP_UNOWNED" >&2
  exit 76
fi

"$SELLER_CENTRAL_BROWSER" \
  --headless=new \
  --disable-gpu \
  --remote-debugging-address=127.0.0.1 \
  "--remote-debugging-port=$CDP_PORT" \
  "--user-data-dir=$SELLER_CENTRAL_PROFILE" \
  --no-first-run \
  --no-default-browser-check \
  about:blank >/dev/null 2>&1 &
browser_pid="$!"
ready=0
for _ in $(seq 1 40); do
  if curl -fsS --max-time 2 "$CDP_URL/json/version" >/dev/null 2>&1; then ready=1; break; fi
  sleep 0.5
done
[[ "$ready" -eq 1 ]] || { echo "Seller Central CDP did not become ready" >&2; exit 75; }

/usr/bin/node "$ROOT/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs" --auth-check
/usr/bin/node "$ROOT/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs" --drain
/usr/bin/node "$ROOT/scripts/amazon-returns/seller-central-bridge-worker.mjs" --drain
