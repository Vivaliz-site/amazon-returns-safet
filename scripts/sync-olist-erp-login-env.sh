#!/usr/bin/env bash
set -euo pipefail

INBOX_DIR="/home/ubuntu/.amazon-returns-secrets-inbox"
INBOX_FILE="$INBOX_DIR/olist-erp-browser-login.env"
DEST_DIR="/home/ubuntu/amazon-returns-deploy/shared"
DEST_FILE="$DEST_DIR/olist-erp-browser-login.env"
VALIDATOR="$(cd "$(dirname "$0")" && pwd)/validate-olist-erp-login-env.py"

[[ "$(id -u)" -eq 0 ]] || { echo "olist_login_env_sync=failed reason=root_required" >&2; exit 77; }
[[ -x "$VALIDATOR" ]] || { echo "olist_login_env_sync=failed reason=validator_missing" >&2; exit 69; }

if [[ ! -e "$INBOX_FILE" ]]; then
  echo "olist_login_env_synced=false reason=no_inbox"
  exit 0
fi

[[ -d "$INBOX_DIR" && ! -L "$INBOX_DIR" ]] || {
  echo "olist_login_env_sync=failed reason=invalid_inbox_dir" >&2
  exit 65
}
[[ "$(stat -c '%U' "$INBOX_DIR")" == "ubuntu" ]] || {
  echo "olist_login_env_sync=failed reason=invalid_inbox_owner" >&2
  exit 65
}
[[ "$(stat -c '%a' "$INBOX_DIR")" == "700" ]] || {
  echo "olist_login_env_sync=failed reason=invalid_inbox_mode" >&2
  exit 65
}

[[ -f "$INBOX_FILE" && ! -L "$INBOX_FILE" ]] || {
  echo "olist_login_env_sync=failed reason=invalid_file_type" >&2
  exit 65
}
[[ "$(stat -c '%U' "$INBOX_FILE")" == "ubuntu" ]] || {
  echo "olist_login_env_sync=failed reason=invalid_owner" >&2
  exit 65
}
[[ "$(stat -c '%a' "$INBOX_FILE")" == "600" ]] || {
  echo "olist_login_env_sync=failed reason=invalid_mode" >&2
  exit 65
}

"$VALIDATOR" "$INBOX_FILE" >/dev/null
install -d -o root -g www-data -m 0770 "$DEST_DIR"
tmp="$(mktemp "$DEST_DIR/.olist-erp-browser-login.env.XXXXXX")"
cleanup() { rm -f "$tmp"; }
trap cleanup EXIT
install -o root -g www-data -m 0640 "$INBOX_FILE" "$tmp"
mv -f "$tmp" "$DEST_FILE"
trap - EXIT
rm -f "$INBOX_FILE"
rmdir "$INBOX_DIR" 2>/dev/null || true

echo "olist_login_env_synced=true"
