#!/usr/bin/env bash
set -euo pipefail

TOTP_USER="amazon-totp"
BASE_DIR="/var/lib/shopvivaliz/amazon-totp"
STATE_DIR="$BASE_DIR/state"
SEED_FILE="$BASE_DIR/seed.base32"
LIB_DIR="/usr/local/lib/shopvivaliz/amazon-totp"
GENERATOR="$LIB_DIR/totp-current.py"
COMMAND="$LIB_DIR/current"
ENROLL="/usr/local/sbin/amazon-totp-enroll"
AUTHORIZED_KEY_FILE=""
SOURCE_ROOT="$(cd "$(dirname "$0")" && pwd)"

while (($#)); do
  case "$1" in
    --authorized-key-file) AUTHORIZED_KEY_FILE="${2:-}"; shift 2 ;;
    --source-root) SOURCE_ROOT="${2:-}"; shift 2 ;;
    *) echo "UNKNOWN_OPTION" >&2; exit 64 ;;
  esac
done

if [[ "$(id -u)" -ne 0 ]]; then
  echo "ROOT_REQUIRED" >&2
  exit 77
fi

SOURCE_GENERATOR="$SOURCE_ROOT/amazon-returns/totp-current.py"
[[ -f "$SOURCE_GENERATOR" ]] || { echo "TOTP_GENERATOR_MISSING" >&2; exit 66; }

if ! id "$TOTP_USER" >/dev/null 2>&1; then
  useradd --system --create-home --home-dir "/var/lib/shopvivaliz/amazon-totp-home" --shell /bin/bash "$TOTP_USER"
fi
passwd -l "$TOTP_USER" >/dev/null 2>&1 || true
TOTP_GROUP="$(id -gn "$TOTP_USER")"
HOME_DIR="$(getent passwd "$TOTP_USER" | cut -d: -f6)"

install -d -o root -g "$TOTP_GROUP" -m 0750 "$BASE_DIR"
install -d -o "$TOTP_USER" -g "$TOTP_GROUP" -m 0700 "$STATE_DIR"
install -d -o root -g root -m 0755 "$LIB_DIR"
install -o root -g root -m 0755 "$SOURCE_GENERATOR" "$GENERATOR"

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
if ! flock -n 9; then
  echo "TOTP_RATE_LIMITED" >&2
  exit 75
fi
now="$(date +%s)"
last=0
[[ -f "$STAMP" ]] && read -r last <"$STAMP" || true
if [[ "$last" =~ ^[0-9]+$ ]] && (( now - last < 20 )); then
  echo "TOTP_RATE_LIMITED" >&2
  exit 75
fi
if [[ ! -f "$SEED" ]]; then
  echo "SEED_NOT_CONFIGURED" >&2
  exit 78
fi
code="$(/usr/bin/python3 /usr/local/lib/shopvivaliz/amazon-totp/totp-current.py --seed-file "$SEED")"
[[ "$code" =~ ^[0-9]{6}$ ]] || { echo "TOTP_GENERATION_FAILED" >&2; exit 78; }
printf '%s\n' "$now" >"$STAMP"
printf '%s\n' "$code"
EOF
chown root:root "$COMMAND"
chmod 0755 "$COMMAND"

cat >"$ENROLL" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
umask 077
SEED="/var/lib/shopvivaliz/amazon-totp/seed.base32"
DIR="$(dirname "$SEED")"
TMP="$(mktemp "$DIR/.seed.XXXXXX")"
restore_tty() { [[ -t 0 ]] && stty echo 2>/dev/null || true; rm -f "$TMP"; }
trap restore_tty EXIT INT TERM
if [[ -t 0 ]]; then
  printf 'Amazon TOTP seed: ' >&2
  stty -echo
  IFS= read -r value
  stty echo
  printf '\n' >&2
else
  IFS= read -r value
fi
value="${value//[[:space:]]/}"
if [[ ${#value} -lt 16 || ! "$value" =~ ^[A-Za-z2-7]+$ ]]; then
  echo "SEED_INVALID" >&2
  exit 64
fi
printf '%s\n' "$value" >"$TMP"
chown amazon-totp:amazon-totp "$TMP"
chmod 0400 "$TMP"
mv -f "$TMP" "$SEED"
trap - EXIT INT TERM
printf 'SEED_ENROLLED\n'
EOF
chown root:root "$ENROLL"
chmod 0700 "$ENROLL"

if [[ -f "$SEED_FILE" ]]; then
  chown "$TOTP_USER:$TOTP_GROUP" "$SEED_FILE"
  chmod 0400 "$SEED_FILE"
fi

install -d -o "$TOTP_USER" -g "$TOTP_GROUP" -m 0700 "$HOME_DIR/.ssh"
AUTHORIZED_KEYS="$HOME_DIR/.ssh/authorized_keys"
touch "$AUTHORIZED_KEYS"
chown "$TOTP_USER:$TOTP_GROUP" "$AUTHORIZED_KEYS"
chmod 0600 "$AUTHORIZED_KEYS"

if [[ -n "$AUTHORIZED_KEY_FILE" ]]; then
  [[ -f "$AUTHORIZED_KEY_FILE" ]] || { echo "AUTHORIZED_KEY_FILE_MISSING" >&2; exit 66; }
  key="$(awk 'NF>=2 && $1 == "ssh-ed25519" {print $1" "$2; exit}' "$AUTHORIZED_KEY_FILE")"
  [[ -n "$key" ]] || { echo "PUBLIC_KEY_INVALID" >&2; exit 65; }
  entry="no-agent-forwarding,no-port-forwarding,no-pty,no-X11-forwarding,no-user-rc,command=\"/usr/local/lib/shopvivaliz/amazon-totp/current\" $key"
  grep -Fqx "$entry" "$AUTHORIZED_KEYS" || printf '%s\n' "$entry" >>"$AUTHORIZED_KEYS"
  chown "$TOTP_USER:$TOTP_GROUP" "$AUTHORIZED_KEYS"
  chmod 0600 "$AUTHORIZED_KEYS"
fi

printf 'TOTP_AUTHENTICATOR_PROVISIONED_NO_SEED\n'