#!/usr/bin/env bash
set -euo pipefail
ROOT="${AMAZON_RETURNS_RELEASE_ROOT:-/home/ubuntu/amazon-returns-deploy/current}"
NODE_BIN="${OLIST_ERP_NODE_BINARY:-}"
if [[ -z "$NODE_BIN" ]]; then
  for candidate in /opt/node-v24.20.0-linux-arm64/bin/node /usr/local/bin/node /usr/bin/node; do
    [[ -x "$candidate" ]] || continue
    NODE_BIN="$candidate"; break
  done
fi
[[ -n "$NODE_BIN" && -x "$NODE_BIN" ]] || { echo "Olist ERP requires Node.js" >&2; exit 69; }
: "${OLIST_ERP_BROWSER:?OLIST_ERP_BROWSER is required}"
: "${OLIST_ERP_BROWSER_PROFILE_DIR:?OLIST_ERP_BROWSER_PROFILE_DIR is required}"
: "${OLIST_ERP_PLAYWRIGHT_CORE:?OLIST_ERP_PLAYWRIGHT_CORE is required}"
mkdir -p "$OLIST_ERP_BROWSER_PROFILE_DIR"
chmod 700 "$OLIST_ERP_BROWSER_PROFILE_DIR"
exec "$NODE_BIN" "$ROOT/scripts/amazon-returns/olist-erp-browser-host.cjs"
