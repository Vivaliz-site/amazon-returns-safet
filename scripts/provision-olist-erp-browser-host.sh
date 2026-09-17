#!/usr/bin/env bash
set -euo pipefail
ROOT="/home/ubuntu/amazon-returns-deploy"
SHARED="$ROOT/shared"
BROWSER_ROOT="$SHARED/olist-erp-browser"
ENV_FILE="$SHARED/olist-erp-browser.env"
LOGIN_ENV_FILE="$SHARED/olist-erp-browser-login.env"
SOURCE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENABLE_SERVICE=0
while (($#)); do
  case "$1" in
    --source-root) SOURCE_ROOT="${2:-}"; shift 2 ;;
    --enable-service) ENABLE_SERVICE=1; shift ;;
    *) echo "unknown option" >&2; exit 64 ;;
  esac
done
[[ "$(id -u)" -eq 0 ]] || { echo "root required" >&2; exit 77; }

browser=""
for candidate in /home/ubuntu/.cache/ms-playwright-arm64/chromium-*/chrome-linux-arm64/chrome /usr/bin/google-chrome-stable /usr/bin/google-chrome /usr/bin/chromium /usr/bin/chromium-browser; do
  [[ -x "$candidate" ]] || continue
  resolved="$(readlink -f "$candidate" 2>/dev/null || true)"
  [[ "$resolved" == "/usr/bin/snap" || "$resolved" == "/snap/bin/chromium" ]] && continue
  browser="$candidate"; break
done
[[ -n "$browser" ]] || { echo "supported non-Snap Chromium browser not installed" >&2; exit 69; }

playwright=""
for candidate in /home/ubuntu/.npm/_npx/*/node_modules/playwright-core /home/ubuntu/.local/lib/node_modules/playwright-core /usr/local/lib/node_modules/playwright-core; do
  [[ -f "$candidate/package.json" ]] || continue
  playwright="$candidate"; break
done
[[ -n "$playwright" ]] || { echo "playwright-core runtime not installed on host" >&2; exit 69; }

install -d -o ubuntu -g www-data -m 0750 "$BROWSER_ROOT"
install -d -o ubuntu -g www-data -m 0700 "$BROWSER_ROOT/profile"
install -d -o ubuntu -g www-data -m 0750 "$BROWSER_ROOT/runtime/node_modules"
rm -rf "$BROWSER_ROOT/runtime/node_modules/playwright-core"
cp -a "$playwright" "$BROWSER_ROOT/runtime/node_modules/playwright-core"
chown -R ubuntu:www-data "$BROWSER_ROOT/runtime"
find "$BROWSER_ROOT/runtime" -type d -exec chmod 0750 {} +
find "$BROWSER_ROOT/runtime" -type f -exec chmod u=rw,g=r,o= {} +
cat >"$ENV_FILE" <<EOF
OLIST_ERP_BROWSER=$browser
OLIST_ERP_BROWSER_PROFILE_DIR=$BROWSER_ROOT/profile
OLIST_ERP_PLAYWRIGHT_CORE=$BROWSER_ROOT/runtime/node_modules/playwright-core
OLIST_ERP_CDP_URL=http://127.0.0.1:9226
OLIST_ERP_CDP_PORT=9226
EOF
chown root:www-data "$ENV_FILE"
chmod 0640 "$ENV_FILE"
touch "$LOGIN_ENV_FILE"
chown root:www-data "$LOGIN_ENV_FILE"
chmod 0640 "$LOGIN_ENV_FILE"
install -o root -g root -m 0644 "$SOURCE_ROOT/deploy/systemd/amazon-returns-olist-erp-browser.service" /etc/systemd/system/amazon-returns-olist-erp-browser.service
systemctl daemon-reload
if [[ "$ENABLE_SERVICE" -eq 1 ]]; then
  systemctl enable --now amazon-returns-olist-erp-browser.service
fi
echo "Olist ERP browser host provisioned; interactive ERP authentication remains separate"
