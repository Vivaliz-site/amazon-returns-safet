#!/usr/bin/env bash
set -euo pipefail

TOTP_USER="amazon-totp"
BASE_DIR="/var/lib/shopvivaliz/amazon-totp"
STATE_DIR="$BASE_DIR/state"
SEED_FILE="$BASE_DIR/seed.base32"
LIB_DIR="/usr/local/lib/shopvivaliz/amazon-totp"
GENERATOR="$LIB_DIR/totp-current.py"
COMMAND="$LIB_DIR/current"
AUTHORIZED_KEY_FILE=""
SOURCE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

while (($#)); do
  case "$1" in
    --authorized-key-file) AUTHORIZED_KEY_FILE="${2:-}"; shift 2 ;;
    --source-root) SOURCE_ROOT="${2:-}"; shift 2 ;;
    *) echo "unknown option" >&2; exit 64 ;;
  esac
done

if [[ "$(id -u)" -ne 0 ]]; then
  echo "root required" >&2
  exit 77
fi

if ! id "$TOTP_USER" >/dev/null 2>&1; then
  useradd --system --create-home --home-dir "/var/lib/shopvivaliz/amazon-totp-home" --shell /bin/sh "$TOTP_USER"
fi
TOTP_GROUP="$(id -gn "$TOTP_USER")"

install -d -o root -g "$TOTP_GROUP" -m 0750 "$BASE_DIR"
install -d -o "$TOTP_USER" -g "$TOTP_GROUP" -m 0700 "$STATE_DIR"
install -d -o root -g root -m 0755 "$LIB_DIR"
install -o root -g root -m 0755 "$SOURCE_ROOT/amazon-returns/totp-current.py" "$GENERATOR"

cat >"$COMMAND" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
umask 077
BASE="/var/lib/shopvivaliz/amazon-totp"
SEED="$BASE/seed.base32"
STATE="$BASE/state"
LOCK="$STATE/request.lock"
STAMP="$STATE/last-request"
exec 9>"$LOCK"
flock -n 9 || exit 75
now="$(date +%s)"
last=0
[[ -f "$STAMP" ]] && read -r last <"$STAMP" || true
if [[ "$last" =~ ^[0-9]+$ ]] && (( now - last < 2 )); then
  exit 75
fi
printf '%s\n' "$now" >"$STAMP"
if [[ ! -f "$SEED" ]]; then
  echo "SEED_NOT_CONFIGURED" >&2
  exit 78
fi
exec /usr/bin/python3 /usr/local/lib/shopvivaliz/amazon-totp/totp-current.py --seed-file "$SEED"
EOF
chown root:root "$COMMAND"
chmod 0755 "$COMMAND"

if [[ -f "$SEED_FILE" ]]; then
  chown root:"$TOTP_GROUP" "$SEED_FILE"
  chmod 0640 "$SEED_FILE"
fi

HOME_DIR="$(getent passwd "$TOTP_USER" | cut -d: -f6)"
install -d -o "$TOTP_USER" -g "$TOTP_GROUP" -m 0700 "$HOME_DIR/.ssh"
AUTHORIZED_KEYS="$HOME_DIR/.ssh/authorized_keys"
touch "$AUTHORIZED_KEYS"
chown "$TOTP_USER:$TOTP_GROUP" "$AUTHORIZED_KEYS"
chmod 0600 "$AUTHORIZED_KEYS"

if [[ -n "$AUTHORIZED_KEY_FILE" ]]; then
  [[ -f "$AUTHORIZED_KEY_FILE" ]] || { echo "authorized key file missing" >&2; exit 66; }
  key="$(tr -d '\r\n' <"$AUTHORIZED_KEY_FILE")"
  [[ "$key" == ssh-ed25519\ * ]] || { echo "only ssh-ed25519 keys are accepted" >&2; exit 65; }
  entry="no-agent-forwarding,no-port-forwarding,no-pty,no-X11-forwarding,no-user-rc,command=\"/usr/local/lib/shopvivaliz/amazon-totp/current\" $key"
  grep -Fqx "$entry" "$AUTHORIZED_KEYS" || printf '%s\n' "$entry" >>"$AUTHORIZED_KEYS"
  chown "$TOTP_USER:$TOTP_GROUP" "$AUTHORIZED_KEYS"
  chmod 0600 "$AUTHORIZED_KEYS"
fi

echo "amazon-totp authenticator provisioned; seed enrollment is separate"
