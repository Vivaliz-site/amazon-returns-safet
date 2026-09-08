#!/usr/bin/env bash
set -euo pipefail

ROOT="${AMAZON_RETURNS_RELEASE_ROOT:-/home/ubuntu/amazon-returns-deploy/current}"
: "${SELLER_CENTRAL_BROWSER:?SELLER_CENTRAL_BROWSER is required}"
: "${SELLER_CENTRAL_PROFILE:?SELLER_CENTRAL_PROFILE is required}"
: "${SELLER_CENTRAL_BRIDGE_TOKEN_FILE:?SELLER_CENTRAL_BRIDGE_TOKEN_FILE is required}"

CDP_PORT="${SELLER_CENTRAL_CDP_PORT:-9225}"
CDP_URL="${SELLER_CENTRAL_CDP_URL:-http://127.0.0.1:${CDP_PORT}}"
PID_FILE="$SELLER_CENTRAL_PROFILE/browser.pid"
browser_pid=""

owned_browser_pid() {
  [[ -f "$PID_FILE" ]] || return 1
  local pid cmd
  IFS= read -r pid <"$PID_FILE" || return 1
  [[ "$pid" =~ ^[0-9]+$ ]] || return 1
  [[ -r "/proc/$pid/cmdline" ]] || return 1
  cmd="$(tr '\0' ' ' <"/proc/$pid/cmdline")"
  [[ "$cmd" == *"$SELLER_CENTRAL_BROWSER"* ]] || return 1
  [[ "$cmd" == *"--remote-debugging-port=$CDP_PORT"* ]] || return 1
  [[ "$cmd" == *"--user-data-dir=$SELLER_CENTRAL_PROFILE"* ]] || return 1
  printf '%s\n' "$pid"
}

stop_owned_browser() {
  local pid=""
  if pid="$(owned_browser_pid)"; then
    kill "$pid" 2>/dev/null || true
    for _ in $(seq 1 20); do
      kill -0 "$pid" 2>/dev/null || break
      sleep 0.25
    done
    if kill -0 "$pid" 2>/dev/null; then
      kill -KILL "$pid" 2>/dev/null || true
    fi
  fi
  rm -f "$PID_FILE"
  browser_pid=""
}

cleanup() {
  stop_owned_browser
}
trap cleanup EXIT INT TERM

mkdir -p "$SELLER_CENTRAL_PROFILE"

if curl -fsS --max-time 2 "$CDP_URL/json/version" >/dev/null 2>&1; then
  if ! browser_pid="$(owned_browser_pid)"; then
    echo "UNMANAGED_SELLER_CENTRAL_CDP" >&2
    exit 75
  fi
else
  if browser_pid="$(owned_browser_pid)"; then
    stop_owned_browser
  else
    rm -f "$PID_FILE"
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
  printf '%s\n' "$browser_pid" >"$PID_FILE"
  chmod 0600 "$PID_FILE"
  ready=0
  for _ in $(seq 1 40); do
    if curl -fsS --max-time 2 "$CDP_URL/json/version" >/dev/null 2>&1; then ready=1; break; fi
    sleep 0.5
  done
  [[ "$ready" -eq 1 ]] || { echo "Seller Central CDP did not become ready" >&2; exit 75; }
fi

/usr/bin/node "$ROOT/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs" --auth-check
/usr/bin/node "$ROOT/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs" --drain
/usr/bin/node "$ROOT/scripts/amazon-returns/seller-central-bridge-worker.mjs" --drain
