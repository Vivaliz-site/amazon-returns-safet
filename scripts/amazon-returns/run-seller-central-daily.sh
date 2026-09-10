#!/usr/bin/env bash
set -euo pipefail

ROOT="${AMAZON_RETURNS_RELEASE_ROOT:-/home/ubuntu/amazon-returns-deploy/current}"
: "${SELLER_CENTRAL_BROWSER:?SELLER_CENTRAL_BROWSER is required}"
: "${SELLER_CENTRAL_PROFILE:?SELLER_CENTRAL_PROFILE is required}"
if [[ -z "${SELLER_CENTRAL_BRIDGE_TOKEN:-}" && ( -z "${SELLER_CENTRAL_BRIDGE_TOKEN_FILE:-}" || ! -s "${SELLER_CENTRAL_BRIDGE_TOKEN_FILE}" ) ]]; then
  echo "Seller Central bridge token is required" >&2
  exit 64
fi

CDP_PORT="${SELLER_CENTRAL_CDP_PORT:-9225}"
CDP_URL="${SELLER_CENTRAL_CDP_URL:-http://127.0.0.1:${CDP_PORT}}"
NODE_BIN="${SELLER_CENTRAL_NODE_BINARY:-}"
if [[ -z "$NODE_BIN" ]]; then
  for candidate in /usr/local/bin/node /usr/bin/node; do
    [[ -x "$candidate" ]] || continue
    if "$candidate" -e 'process.exit(typeof WebSocket === "function" ? 0 : 1)' >/dev/null 2>&1; then
      NODE_BIN="$candidate"
      break
    fi
  done
fi
if [[ -z "$NODE_BIN" || ! -x "$NODE_BIN" ]] || ! "$NODE_BIN" -e 'process.exit(typeof WebSocket === "function" ? 0 : 1)' >/dev/null 2>&1; then
  echo "Seller Central requires Node.js with global WebSocket support" >&2
  exit 69
fi
browser_pid=""
cleanup() {
  [[ -n "$browser_pid" ]] || return 0
  if kill -0 -- "-$browser_pid" 2>/dev/null; then
    kill -TERM -- "-$browser_pid" 2>/dev/null || true
    for _ in $(seq 1 30); do
      kill -0 -- "-$browser_pid" 2>/dev/null || break
      sleep 0.1
    done
    if kill -0 -- "-$browser_pid" 2>/dev/null; then
      kill -KILL -- "-$browser_pid" 2>/dev/null || true
    fi
  fi
  wait "$browser_pid" 2>/dev/null || true
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p "$SELLER_CENTRAL_PROFILE"

if curl -fsS --max-time 2 "$CDP_URL/json/version" >/dev/null 2>&1; then
  echo "PREEXISTING_CDP_UNOWNED" >&2
  exit 76
fi

setsid "$SELLER_CENTRAL_BROWSER" \
  --headless=new \
  --no-sandbox \
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

"$NODE_BIN" "$ROOT/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs" --auth-check
if [[ " ${*:-} " == *" --auth-check-only "* ]]; then
  exit 0
fi
"$NODE_BIN" "$ROOT/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs" --drain
bridge_mode="--drain"
if [[ " ${*:-} " == *" --bridge-once "* ]]; then
  bridge_mode="--once"
fi
"$NODE_BIN" "$ROOT/scripts/amazon-returns/seller-central-bridge-worker.mjs" "$bridge_mode"
