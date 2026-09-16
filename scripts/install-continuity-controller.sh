#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_PATH="${CONTINUITY_CONFIG:-/etc/agent-continuity/config.json}"
SERVICE_USER="agent-continuity"
WORKER_USER="agent-continuity-worker"
PUBLISHER_USER="agent-continuity-publisher"
JOB_GROUP="agent-continuity-jobs"
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
ensure_group "$JOB_GROUP"
for user in "$SERVICE_USER" "$WORKER_USER" "$PUBLISHER_USER"; do
  usermod -a -G "$JOB_GROUP" "$user"
done

SOURCE_SHA="$(git -c "safe.directory=$REPO_ROOT" -C "$REPO_ROOT" rev-parse HEAD)"
CONFIG_DIR="$(dirname "$CONFIG_PATH")"
install -d -m 0750 -o root -g "$SERVICE_USER" "$CONFIG_DIR"
chown root:"$SERVICE_USER" "$CONFIG_PATH"
chmod 0640 "$CONFIG_PATH"

install -d -m 0750 -o "$SERVICE_USER" -g "$JOB_GROUP" "$STATE_ROOT"
install -d -m 0770 -o "$SERVICE_USER" -g "$JOB_GROUP" \
  "$STATE_ROOT/jobs" "$STATE_ROOT/jobs/pending" "$STATE_ROOT/jobs/running" \
  "$STATE_ROOT/jobs/receipts" "$STATE_ROOT/jobs/packets"
install -d -m 0750 -o "$PUBLISHER_USER" -g "$PUBLISHER_USER" "$STATE_ROOT/publisher"

install -d -m 0755 -o root -g root "$WORKTREE_ROOT"
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_USER" \
  "$WORKTREE_ROOT/repositories" "$REPOSITORY_MOUNT_ROOT"
install -d -m 0770 -o "$WORKER_USER" -g "$JOB_GROUP" \
  "$WORKTREE_ROOT/sources" "$WORKTREE_ROOT/worktrees"
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
