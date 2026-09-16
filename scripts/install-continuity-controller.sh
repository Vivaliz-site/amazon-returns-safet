#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_PATH="${CONTINUITY_CONFIG:-/etc/agent-continuity/config.json}"
SERVICE_USER="agent-continuity"
WORKER_USER="agent-continuity-worker"
PUBLISHER_USER="agent-continuity-publisher"
STATE_ROOT="/var/lib/agent-continuity"
WORKTREE_ROOT="/srv/continuity"
RUNTIME_ROOT="/opt/agent-continuity"
REPOSITORY_MOUNT_ROOT="$WORKTREE_ROOT/repositories/amazon-returns-safet"
POLICY_PATH="/etc/agent-continuity/gemini-admin-policy.toml"

if [[ ! -f "$CONFIG_PATH" ]]; then
  echo "config file is required and will not be created or overwritten: $CONFIG_PATH" >&2
  exit 2
fi

python3 - "$CONFIG_PATH" <<'PY'
import json
from pathlib import Path
import sys
path = Path(sys.argv[1])
data = json.loads(path.read_text(encoding="utf-8"))
if not isinstance(data.get("repositories"), list) or not data.get("ledger_path"):
    raise SystemExit("continuity config must define ledger_path and repositories")
PY

if [[ "$EUID" -ne 0 ]]; then
  echo "run this installer as root after preparing the config file" >&2
  exit 3
fi

ensure_group() {
  local group="$1"
  getent group "$group" >/dev/null || groupadd --system "$group"
}

ensure_user() {
  local user="$1" home="$2"
  ensure_group "$user"
  if ! id -u "$user" >/dev/null 2>&1; then
    useradd --system --gid "$user" --home-dir "$home" --no-create-home --shell /usr/sbin/nologin "$user"
  fi
}

ensure_user "$SERVICE_USER" "$STATE_ROOT"
ensure_user "$WORKER_USER" "/var/lib/$WORKER_USER"
ensure_user "$PUBLISHER_USER" "/var/lib/$PUBLISHER_USER"
LEGACY_JOB_GROUP="agent-continuity-jobs"
if getent group "$LEGACY_JOB_GROUP" >/dev/null; then
  for user in "$SERVICE_USER" "$WORKER_USER" "$PUBLISHER_USER"; do
    gpasswd -d "$user" "$LEGACY_JOB_GROUP" >/dev/null 2>&1 || true
  done
fi
install -d -m 0700 -o "$WORKER_USER" -g "$WORKER_USER" "/var/lib/$WORKER_USER"
install -d -m 0700 -o "$PUBLISHER_USER" -g "$PUBLISHER_USER" "/var/lib/$PUBLISHER_USER"

SOURCE_SHA="$(git -c "safe.directory=$REPO_ROOT" -C "$REPO_ROOT" rev-parse HEAD)"
CONFIG_DIR="$(dirname "$CONFIG_PATH")"
install -d -m 0750 -o root -g "$SERVICE_USER" "$CONFIG_DIR"
chown root:"$SERVICE_USER" "$CONFIG_PATH"
chmod 0640 "$CONFIG_PATH"

GEMINI_BIN="${CONTINUITY_GEMINI_BIN:-}"
if [[ -z "$GEMINI_BIN" ]]; then
  GEMINI_BIN="$(command -v gemini 2>/dev/null || true)"
fi
if [[ -z "$GEMINI_BIN" ]]; then
  for candidate in /opt/node-v*/bin/gemini; do
    [[ -x "$candidate" ]] && GEMINI_BIN="$candidate"
  done
fi
CONTINUITY_AUTO_DISPATCH="$(python3 - "$CONFIG_PATH" <<'PYCFG'
import json, sys
value = json.load(open(sys.argv[1], encoding="utf-8")).get("auto_dispatch")
print("true" if value is True else "false")
PYCFG
)"
if [[ "$CONTINUITY_AUTO_DISPATCH" == "false" ]]; then
  systemctl stop agent-continuity-controller.timer agent-continuity-controller.service \
    agent-continuity-worker.path agent-continuity-worker.service \
    agent-continuity-publisher.path agent-continuity-publisher.service >/dev/null 2>&1 || true
  PREP_ARGS=("$CONFIG_PATH" --worker-uid "$(id -u "$WORKER_USER")")
  if [[ -n "$GEMINI_BIN" ]]; then
    PREP_ARGS+=(--gemini-bin "$GEMINI_BIN")
  fi
  python3 "$REPO_ROOT/scripts/prepare-continuity-runtime-config.py" "${PREP_ARGS[@]}"
else
  echo 'runtime_config_prep_skipped=auto_dispatch_enabled'
fi

install -d -m 0755 -o root -g root "$STATE_ROOT" "$STATE_ROOT/jobs"
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_USER" "$STATE_ROOT/controller"
python3 "$REPO_ROOT/scripts/migrate-continuity-state-layout.py" \
  --config "$CONFIG_PATH" --state-root "$STATE_ROOT" --controller-state "$STATE_ROOT/controller"
for ledger_file in "$STATE_ROOT/controller"/ledger.sqlite3*; do
  [[ -e "$ledger_file" ]] || continue
  chown "$SERVICE_USER":"$SERVICE_USER" "$ledger_file"
  chmod 0640 "$ledger_file"
done
install -d -m 2750 -o "$SERVICE_USER" -g "$WORKER_USER" \
  "$STATE_ROOT/jobs/pending" "$STATE_ROOT/jobs/packets"
install -d -m 2750 -o "$WORKER_USER" -g "$PUBLISHER_USER" \
  "$STATE_ROOT/jobs/running" "$STATE_ROOT/jobs/receipts"
install -d -m 0750 -o "$PUBLISHER_USER" -g "$PUBLISHER_USER" "$STATE_ROOT/publisher"

install -d -m 0755 -o root -g root "$WORKTREE_ROOT"
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_USER" \
  "$WORKTREE_ROOT/repositories" "$REPOSITORY_MOUNT_ROOT"
install -d -m 2750 -o "$WORKER_USER" -g "$SERVICE_USER" \
  "$WORKTREE_ROOT/sources" "$WORKTREE_ROOT/worktrees"

normalize_queue_files() {
  local directory="$1" owner="$2" group="$3"
  [[ -d "$directory" ]] || return 0
  find "$directory" -type f -exec chown "$owner:$group" {} + -exec chmod 0640 {} +
}
normalize_queue_files "$STATE_ROOT/jobs/pending" "$SERVICE_USER" "$WORKER_USER"
normalize_queue_files "$STATE_ROOT/jobs/packets" "$SERVICE_USER" "$WORKER_USER"
normalize_queue_files "$STATE_ROOT/jobs/running" "$WORKER_USER" "$PUBLISHER_USER"
normalize_queue_files "$STATE_ROOT/jobs/receipts" "$WORKER_USER" "$PUBLISHER_USER"
for tree in "$WORKTREE_ROOT/sources" "$WORKTREE_ROOT/worktrees"; do
  chown -R --no-dereference "$WORKER_USER:$SERVICE_USER" "$tree"
  find "$tree" -type d -exec chmod u+rwx,g+rx,g-w,o-rwx,g+s {} +
  find "$tree" -type f -exec chmod u+rw,g+rX,g-w,o-rwx {} +
done

install -d -m 0755 -o root -g root "$RUNTIME_ROOT" "$RUNTIME_ROOT/releases"

install -m 0644 -o root -g root \
  "$REPO_ROOT/deploy/continuity/gemini-admin-policy.toml" "$POLICY_PATH"

RELEASE_DIR="$RUNTIME_ROOT/releases/$SOURCE_SHA"
if [[ ! -d "$RELEASE_DIR" ]]; then
  STAGE_DIR="$(mktemp -d "$RUNTIME_ROOT/releases/.stage.XXXXXX")"
  chmod 0755 "$STAGE_DIR"
  trap 'rm -rf "${STAGE_DIR:-}"' EXIT
  install -d -m 0755 -o root -g root "$STAGE_DIR/tools" "$STAGE_DIR/tools/continuity"
  if [[ -f "$REPO_ROOT/tools/__init__.py" ]]; then
    install -m 0644 -o root -g root "$REPO_ROOT/tools/__init__.py" "$STAGE_DIR/tools/__init__.py"
  else
    install -m 0644 -o root -g root /dev/null "$STAGE_DIR/tools/__init__.py"
  fi
  install -m 0644 -o root -g root "$REPO_ROOT"/tools/continuity/*.py "$STAGE_DIR/tools/continuity/"
  mv "$STAGE_DIR" "$RELEASE_DIR"
  STAGE_DIR=""
  trap - EXIT
fi
ln -sfn "releases/$SOURCE_SHA" "$RUNTIME_ROOT/current.next"
mv -Tf "$RUNTIME_ROOT/current.next" "$RUNTIME_ROOT/current"
printf '%s\n' "$SOURCE_SHA" > "$RUNTIME_ROOT/.source-sha.tmp"
chmod 0644 "$RUNTIME_ROOT/.source-sha.tmp"
mv -f "$RUNTIME_ROOT/.source-sha.tmp" "$RUNTIME_ROOT/.source-sha"

for unit in \
  agent-continuity-controller.service agent-continuity-controller.timer \
  agent-continuity-worker.service agent-continuity-worker.path \
  agent-continuity-publisher.service agent-continuity-publisher.path; do
  install -m 0644 "$REPO_ROOT/deploy/systemd/$unit" "/etc/systemd/system/$unit"
done

systemctl daemon-reload
systemctl enable --now agent-continuity-controller.timer
systemctl enable agent-continuity-worker.path agent-continuity-publisher.path
