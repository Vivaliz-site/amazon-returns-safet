#!/usr/bin/env bash
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG_PATH="${CONTINUITY_CONFIG:-/etc/agent-continuity/config.json}"
SERVICE_USER="agent-continuity"
STATE_ROOT="/var/lib/agent-continuity"
WORKTREE_ROOT="/srv/continuity"

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

install -d -m 0750 -o "$SERVICE_USER" -g "$SERVICE_USER" "$STATE_ROOT" "$WORKTREE_ROOT"
chgrp "$SERVICE_USER" "$CONFIG_PATH"
chmod g+r "$CONFIG_PATH"
install -m 0644 "$REPO_ROOT/deploy/systemd/agent-continuity-controller.service" /etc/systemd/system/agent-continuity-controller.service
install -m 0644 "$REPO_ROOT/deploy/systemd/agent-continuity-controller.timer" /etc/systemd/system/agent-continuity-controller.timer

systemctl daemon-reload
systemctl enable --now agent-continuity-controller.timer
