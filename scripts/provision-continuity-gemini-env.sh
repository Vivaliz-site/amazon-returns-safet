#!/usr/bin/env bash
set -euo pipefail

SOURCE="${CONTINUITY_AI_SECRET_SOURCE:-/home/ubuntu/amazon-returns-deploy/ai-secrets.env}"
DEST="${CONTINUITY_GEMINI_ENV:-/etc/agent-continuity/gemini.env}"
WORKER_GROUP="agent-continuity-worker"
DEFAULT_MODEL="${CONTINUITY_GEMINI_MODEL:-gemini-3.1-flash-lite}"

if [[ "$EUID" -ne 0 ]]; then
  echo "run as root" >&2
  exit 2
fi
if [[ ! -r "$SOURCE" ]]; then
  echo "protected Gemini secret source is unavailable" >&2
  exit 3
fi
if ! getent group "$WORKER_GROUP" >/dev/null; then
  echo "worker group is unavailable" >&2
  exit 4
fi

install -d -m 0750 -o root -g "$WORKER_GROUP" "$(dirname "$DEST")"
TMP="$(mktemp "${DEST}.XXXXXX")"
trap 'rm -f "${TMP:-}"' EXIT
python3 - "$SOURCE" "$TMP" "$DEFAULT_MODEL" <<'PY'
from pathlib import Path
import sys

source = Path(sys.argv[1])
dest = Path(sys.argv[2])
fallback_model = sys.argv[3]
values = {}
for raw in source.read_text(encoding="utf-8").splitlines():
    line = raw.strip()
    if not line or line.startswith("#"):
        continue
    if line.startswith("export "):
        line = line[7:].lstrip()
    if "=" not in line:
        continue
    key, value = line.split("=", 1)
    if key.strip() in {"GEMINI_API_KEY", "GEMINI_MODEL"}:
        values[key.strip()] = value.strip()
key = values.get("GEMINI_API_KEY", "")
if not key:
    raise SystemExit("GEMINI_API_KEY is missing from protected source")
model = values.get("GEMINI_MODEL") or fallback_model
dest.write_text(f"GEMINI_API_KEY={key}\nGEMINI_MODEL={model}\n", encoding="utf-8")
PY

chown root:"$WORKER_GROUP" "$TMP"
chmod 0640 "$TMP"
mv -f "$TMP" "$DEST"
trap - EXIT
printf '%s\n' 'GEMINI_ENV_PROVISIONED=true'
