#!/usr/bin/env bash
set -Eeuo pipefail

root="${SHOPVIVALIZ_DEPLOY_ROOT:-/home/ubuntu/shopvivaliz-deploy}"
current="$root/current"
shared="$root/shared"
service_name='shopvivaliz-amazon-returns.service'
unit_source="$current/deploy/systemd/$service_name"
env_file="$shared/.env"
runtime_dir="$shared/amazon-returns"

[[ -r "$unit_source" ]] || { echo "missing unit: $unit_source" >&2; exit 2; }
[[ -f "$env_file" ]] || { echo "missing shared env: $env_file" >&2; exit 2; }

install -d -o www-data -g www-data -m 0750 "$runtime_dir"
install -d -o www-data -g www-data -m 0750 "$runtime_dir/evidence"
python3 - "$env_file" <<'PY'
from pathlib import Path
import os, re, sys, tempfile

path = Path(sys.argv[1])
defaults = {
    'AMAZON_RETURNS_ENABLED': '1',
    'AMAZON_RETURNS_MODE': 'production',
    'AMAZON_RETURNS_GMAIL_INGEST': '1',
    'AMAZON_RETURNS_SAFE_T_WRITE': '0',
    'AMAZON_RETURNS_APPEAL_WRITE': '0',
    'AMAZON_RETURNS_SUPPORT_WRITE': '0',
    'AMAZON_RETURNS_POLICY_MONITOR': '1',
}
lines = path.read_text(encoding='utf-8').splitlines()
assigned = {}
pattern = re.compile(r'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$')
for line in lines:
    match = pattern.match(line)
    if match:
        assigned[match.group(1)] = match.group(2).strip().strip("'\"")
for key, value in defaults.items():
    if not assigned.get(key):
        lines.append(f'{key}={value}')
stat = path.stat()
fd, name = tempfile.mkstemp(prefix='.amazon-returns-env.', dir=path.parent)
try:
    with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as handle:
        handle.write('\n'.join(lines).rstrip('\n') + '\n')
        handle.flush(); os.fsync(handle.fileno())
    os.chmod(name, stat.st_mode & 0o777)
    if hasattr(os, 'chown'):
        os.chown(name, stat.st_uid, stat.st_gid)
    os.replace(name, path)
finally:
    try: os.unlink(name)
    except FileNotFoundError: pass
print('amazon_returns_runtime_defaults_ready=true')
PY

install -m 0644 "$unit_source" "/etc/systemd/system/$service_name"
systemctl daemon-reload
systemctl enable --now "$service_name"
systemctl restart "$service_name"
test "$(systemctl is-active "$service_name")" = active
test "$(systemctl is-enabled "$service_name")" = enabled
echo 'amazon_returns_service_ready=true'
