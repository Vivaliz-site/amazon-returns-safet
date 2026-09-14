#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_PATH="${CONTINUITY_CONFIG:-/etc/agent-continuity/config.json}"
SERVICE_USER="agent-continuity"
STATE_ROOT="/var/lib/agent-continuity"
WORKTREE_ROOT="/srv/continuity"
RUNTIME_ROOT="/opt/agent-continuity"
REPOSITORY_MOUNT_ROOT="$WORKTREE_ROOT/repositories/amazon-returns-safet"

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

if [[ "${EUID}" -ne 0 ]]; then
  echo "run this installer as root after preparing the config file" >&2
  exit 3
fi

if ! id -u "$SERVICE_USER" >/dev/null 2>&1; then
  useradd --system --home-dir "$STATE_ROOT" --create-home --shell /usr/sbin/nologin "$SERVICE_USER"
fi

SOURCE_SHA="$(git -c "safe.directory=$REPO_ROOT" -C "$REPO_ROOT" rev-parse HEAD)"
CONFIG_DIR="$(dirname "$CONFIG_PATH")"
install -d -m 0750 -o root -g "$SERVICE_USER" "$CONFIG_DIR"
chown root:"$SERVICE_USER" "$CONFIG_PATH"
chmod 0640 "$CONFIG_PATH"
install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_USER" \
  "$STATE_ROOT" "$WORKTREE_ROOT" "$WORKTREE_ROOT/repositories" "$REPOSITORY_MOUNT_ROOT"
install -d -m 0755 -o root -g root "$RUNTIME_ROOT" "$RUNTIME_ROOT/releases"

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

install -m 0644 "$REPO_ROOT/deploy/systemd/agent-continuity-controller.service" /etc/systemd/system/agent-continuity-controller.service
install -m 0644 "$REPO_ROOT/deploy/systemd/agent-continuity-controller.timer" /etc/systemd/system/agent-continuity-controller.timer

systemctl daemon-reload
systemctl enable --now agent-continuity-controller.timer
