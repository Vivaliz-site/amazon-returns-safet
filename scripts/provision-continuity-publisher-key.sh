#!/usr/bin/env bash
set -euo pipefail

REPOSITORY="${CONTINUITY_PUBLISHER_REPOSITORY:-Vivaliz-site/amazon-returns-safet}"
KEY_DIR="${CONTINUITY_PUBLISHER_KEY_DIR:-/etc/agent-continuity/publisher}"
KEY_PATH="$KEY_DIR/id_ed25519"
KNOWN_HOSTS="${CONTINUITY_PUBLISHER_KNOWN_HOSTS:-/etc/agent-continuity/publisher-known-hosts}"
TITLE="${CONTINUITY_PUBLISHER_KEY_TITLE:-shopvivaliz-continuity-publisher}"
PROOF_PATH="$KEY_DIR/deploy-key.json"

if [[ "$EUID" -ne 0 ]]; then
  echo "run as root" >&2
  exit 2
fi
if ! command -v gh >/dev/null || ! command -v ssh-keygen >/dev/null; then
  echo "gh and ssh-keygen are required" >&2
  exit 3
fi

gh auth status -h github.com >/dev/null 2>&1 || {
  echo "one-time authenticated gh session is required" >&2
  exit 4
}
install -d -m 0700 -o root -g root "$KEY_DIR"
if [[ ! -f "$KEY_PATH" ]]; then
  ssh-keygen -q -t ed25519 -N '' -C 'agent-continuity-publisher' -f "$KEY_PATH"
fi
chown root:root "$KEY_PATH" "$KEY_PATH.pub"
chmod 0600 "$KEY_PATH"
chmod 0644 "$KEY_PATH.pub"
META_TMP="$(mktemp)"
KEYS_TMP="$(mktemp)"
RESP_TMP="$(mktemp)"
trap 'rm -f "$META_TMP" "$KEYS_TMP" "$RESP_TMP"' EXIT

python3 - "$META_TMP" <<'PY'
from pathlib import Path
import json
import sys
from urllib.request import Request, urlopen
req = Request(
    "https://api.github.com/meta",
    headers={"Accept": "application/vnd.github+json", "User-Agent": "shopvivaliz-continuity-provisioner"},
)
with urlopen(req, timeout=20) as response:
    data = json.load(response)
keys = data.get("ssh_keys") or []
if not keys:
    raise SystemExit("GitHub meta API returned no SSH host keys")
Path(sys.argv[1]).write_text("".join(f"github.com {key}\n" for key in keys), encoding="utf-8")
PY
install -m 0644 -o root -g root "$META_TMP" "$KNOWN_HOSTS"

gh api "repos/$REPOSITORY/keys?per_page=100" > "$KEYS_TMP"
EXISTING="$(python3 - "$KEYS_TMP" "$KEY_PATH.pub" <<'PY'
from pathlib import Path
import json
import sys
def norm(value):
    parts = str(value).strip().split()
    return " ".join(parts[:2])
items = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
wanted = norm(Path(sys.argv[2]).read_text(encoding="utf-8"))
for item in items:
    if norm(item.get("key", "")) == wanted:
        print(f"{item.get('id')}:{str(bool(item.get('read_only'))).lower()}")
        break
PY
)"

if [[ -n "$EXISTING" ]]; then
  KEY_ID="${EXISTING%%:*}"
  READ_ONLY="${EXISTING##*:}"
  if [[ "$READ_ONLY" != "false" ]]; then
    echo "existing matching deploy key is read-only; refusing to broaden it silently" >&2
    exit 5
  fi
else
  PUBLIC_KEY="$(cat "$KEY_PATH.pub")"
  gh api -X POST "repos/$REPOSITORY/keys" \
    -f title="$TITLE" -f key="$PUBLIC_KEY" -F read_only=false > "$RESP_TMP"
  KEY_ID="$(python3 - "$RESP_TMP" <<'PY'
from pathlib import Path
import json, sys
item=json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
if item.get("read_only") is not False or not item.get("id"):
    raise SystemExit("write-enabled deploy key verification failed")
print(item["id"])
PY
)"
fi
gh api "repos/$REPOSITORY/keys/$KEY_ID" > "$RESP_TMP"
python3 - "$RESP_TMP" "$KEY_PATH.pub" <<'PY'
from pathlib import Path
import json, sys
def norm(value):
    parts = str(value).strip().split()
    return " ".join(parts[:2])
item = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
wanted = norm(Path(sys.argv[2]).read_text(encoding="utf-8"))
if norm(item.get("key", "")) != wanted:
    raise SystemExit("deploy key fingerprint does not match generated public key")
if item.get("read_only") is not False:
    raise SystemExit("deploy key is not write-enabled")
PY

PROOF_TMP="$(mktemp "$KEY_DIR/.deploy-key.json.XXXXXX")"
python3 - "$RESP_TMP" "$KEY_PATH.pub" "$REPOSITORY" "$PROOF_TMP" <<'PY'
from pathlib import Path
import hashlib, json, sys
item = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
parts = Path(sys.argv[2]).read_text(encoding="utf-8").strip().split()[:2]
normalized = " ".join(parts)
proof = {
    "repository": sys.argv[3],
    "key_id": item.get("id"),
    "read_only": bool(item.get("read_only")),
    "public_key_sha256": hashlib.sha256(normalized.encode("utf-8")).hexdigest(),
}
Path(sys.argv[4]).write_text(json.dumps(proof, sort_keys=True) + "\n", encoding="utf-8")
PY
chmod 0600 "$PROOF_TMP"
chown root:root "$PROOF_TMP"
mv -f "$PROOF_TMP" "$PROOF_PATH"

printf 'PUBLISHER_KEY_PROVISIONED=true key_id=%s\n' "$KEY_ID"
