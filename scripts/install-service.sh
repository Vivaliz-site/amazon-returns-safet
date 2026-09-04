#!/usr/bin/env bash
set -Eeuo pipefail

root="${AMAZON_RETURNS_DEPLOY_ROOT:-/home/ubuntu/amazon-returns-deploy}"
current="$root/current"
shared="$root/shared"
service_name='amazon-returns-safet.service'
unit_source="$current/deploy/systemd/$service_name"
env_file="$shared/.env"

[[ -r "$unit_source" ]] || { echo "missing unit: $unit_source" >&2; exit 2; }
[[ -f "$env_file" ]] || { echo "missing env: $env_file" >&2; exit 2; }
install -d -o www-data -g www-data -m 0750 "$shared"
install -d -o www-data -g www-data -m 0750 "$shared/evidence"
chmod 0640 "$env_file"
chown root:www-data "$env_file"

install -m 0644 "$unit_source" "/etc/systemd/system/$service_name"
systemctl daemon-reload
systemctl enable --now "$service_name"
systemctl restart "$service_name"
test "$(systemctl is-active "$service_name")" = active
test "$(systemctl is-enabled "$service_name")" = enabled
echo 'amazon_returns_service_ready=true'
