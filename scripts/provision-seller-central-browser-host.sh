#!/usr/bin/env bash
set -euo pipefail

ROOT="/home/ubuntu/amazon-returns-deploy"
SHARED="$ROOT/shared"
BROWSER_ROOT="$SHARED/seller-central-browser"
ENV_FILE="$SHARED/seller-central-browser.env"
SOURCE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TOTP_HOST=""
ENABLE_TIMER=0

while (($#)); do
  case "$1" in
    --totp-host) TOTP_HOST="${2:-}"; shift 2 ;;
    --source-root) SOURCE_ROOT="${2:-}"; shift 2 ;;
    --enable-timer) ENABLE_TIMER=1; shift ;;
    *) echo "unknown option" >&2; exit 64 ;;
  esac
done

[[ "$(id -u)" -eq 0 ]] || { echo "root required" >&2; exit 77; }
[[ -n "$TOTP_HOST" ]] || { echo "--totp-host is required" >&2; exit 64; }

browser=""
for candidate in /home/ubuntu/.cache/ms-playwright-arm64/chromium-*/chrome-linux-arm64/chrome /usr/bin/google-chrome-stable /usr/bin/google-chrome /usr/bin/chromium /usr/bin/chromium-browser; do
  [[ -x "$candidate" ]] || continue
  resolved="$(readlink -f "$candidate" 2>/dev/null || true)"
  if [[ "$resolved" == "/usr/bin/snap" || "$resolved" == "/snap/bin/chromium" ]]; then
    continue
  fi
  browser="$candidate"
  break
done
[[ -n "$browser" ]] || { echo "supported non-Snap Chromium browser not installed" >&2; exit 69; }

install -d -o ubuntu -g www-data -m 0750 "$BROWSER_ROOT"
install -d -o ubuntu -g www-data -m 0700 "$BROWSER_ROOT/profile"

cat >"$ENV_FILE" <<EOF
SELLER_CENTRAL_BROWSER=$browser
SELLER_CENTRAL_PROFILE=$BROWSER_ROOT/profile
SELLER_CENTRAL_CDP_URL=http://127.0.0.1:9225
SELLER_CENTRAL_CDP_PORT=9225
SELLER_CENTRAL_BRIDGE_TOKEN_FILE=$BROWSER_ROOT/bridge.token
SELLER_CENTRAL_USERNAME_FILE=$BROWSER_ROOT/amazon.username
SELLER_CENTRAL_PASSWORD_FILE=$BROWSER_ROOT/amazon.password
SELLER_CENTRAL_TOTP_HOST=$TOTP_HOST
SELLER_CENTRAL_TOTP_KEY_FILE=$BROWSER_ROOT/totp_ed25519
SELLER_CENTRAL_TOTP_KNOWN_HOSTS_FILE=$BROWSER_ROOT/totp_known_hosts
SELLER_CENTRAL_TOTP_SSH_BINARY=/usr/bin/ssh
SELLER_CENTRAL_WORKER_ID=vm-a1-seller-central
SELLER_CENTRAL_STATUS_WORKER_ID=vm-a1-safe-t-status
EOF
chown root:www-data "$ENV_FILE"
chmod 0640 "$ENV_FILE"

install -o root -g root -m 0644 "$SOURCE_ROOT/deploy/systemd/amazon-returns-seller-central-browser.service" /etc/systemd/system/amazon-returns-seller-central-browser.service
install -o root -g root -m 0644 "$SOURCE_ROOT/deploy/systemd/amazon-returns-seller-central-auth-check.service" /etc/systemd/system/amazon-returns-seller-central-auth-check.service
install -o root -g root -m 0644 "$SOURCE_ROOT/deploy/systemd/amazon-returns-seller-central-browser.timer" /etc/systemd/system/amazon-returns-seller-central-browser.timer
systemctl daemon-reload
if [[ "$ENABLE_TIMER" -eq 1 ]]; then
  systemctl enable --now amazon-returns-seller-central-browser.timer
fi

echo "Seller Central browser host provisioned; credentials and enrollment remain separate"
