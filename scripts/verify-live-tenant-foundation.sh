#!/usr/bin/env bash
set -Eeuo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo 'verify-live-tenant-foundation requires root' >&2; exit 2; }
target_db="${AMAZON_RETURNS_TARGET_DB:-amazon_returns_safet}"
tenant_slug="${AMAZON_RETURNS_TENANT_SLUG:-shopvivaliz}"
connection_key="${AMAZON_RETURNS_CONNECTION_KEY:-amazon-br-primary}"
expected_cases="${AMAZON_RETURNS_EXPECTED_CASES:-}"
output_file="${AMAZON_RETURNS_VERIFICATION_OUTPUT:-}"
env_file="${AMAZON_RETURNS_ENV_FILE:-}"

[[ "$target_db" =~ ^[A-Za-z0-9_]+$ ]] || { echo 'invalid target database name' >&2; exit 2; }
[[ "$tenant_slug" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid tenant slug' >&2; exit 2; }
[[ "$connection_key" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid connection key' >&2; exit 2; }
[[ "$expected_cases" =~ ^[1-9][0-9]*$ ]] || { echo 'invalid expected case count' >&2; exit 2; }

summary_file="$(mktemp)"
trap 'rm -f "$summary_file"' EXIT
emit() { printf '%s\n' "$*" | tee -a "$summary_file"; }
scalar() {
    mysql --protocol=socket -uroot --batch --skip-column-names "$target_db" -e "$1"
}

tenant_id="$(scalar "SELECT id FROM amazon_return_tenants WHERE slug='$tenant_slug' AND status='ACTIVE' LIMIT 1")"
[[ "$tenant_id" =~ ^[0-9]+$ ]] || { echo 'active tenant not found' >&2; exit 1; }
connection_id="$(scalar "SELECT id FROM amazon_return_connections WHERE tenant_id=$tenant_id AND connection_key='$connection_key' AND status='ACTIVE' LIMIT 1")"
[[ "$connection_id" =~ ^[0-9]+$ ]] || { echo 'active tenant connection not found' >&2; exit 1; }
tenant_count="$(scalar "SELECT COUNT(*) FROM amazon_return_tenants WHERE slug='$tenant_slug' AND status='ACTIVE'")"
connection_count="$(scalar "SELECT COUNT(*) FROM amazon_return_connections WHERE tenant_id=$tenant_id AND connection_key='$connection_key' AND status='ACTIVE'")"
target_current_cases="$(scalar "SELECT COUNT(*) FROM amazon_return_cases WHERE tenant_id=$tenant_id AND amazon_connection_id=$connection_id")"
[[ "$tenant_count" -eq 1 ]] || { echo "tenant_count=$tenant_count" >&2; exit 1; }
[[ "$connection_count" -eq 1 ]] || { echo "connection_count=$connection_count" >&2; exit 1; }
[[ "$target_current_cases" -eq "$expected_cases" ]] || {
    echo "target_case_count_unexpected expected=$expected_cases actual=$target_current_cases" >&2
    exit 1
}

ownership_nulls=0
for table in amazon_return_cases amazon_return_events amazon_return_outbox amazon_return_dead_letters amazon_return_evidence amazon_return_source_cursors amazon_return_overrides; do
    count="$(scalar "SELECT COUNT(*) FROM \`$table\` WHERE tenant_id IS NULL OR amazon_connection_id IS NULL")"
    ownership_nulls=$((ownership_nulls + count))
done
for table in amazon_return_connections amazon_return_policies amazon_return_tenant_users; do
    count="$(scalar "SELECT COUNT(*) FROM \`$table\` WHERE tenant_id IS NULL")"
    ownership_nulls=$((ownership_nulls + count))
done
count="$(scalar "SELECT COUNT(*) FROM amazon_return_feature_flags WHERE tenant_id IS NULL OR amazon_connection_id IS NULL")"
ownership_nulls=$((ownership_nulls + count))

case_connection_mismatches="$(scalar "SELECT COUNT(*) FROM amazon_return_cases c LEFT JOIN amazon_return_connections a ON a.id=c.amazon_connection_id WHERE a.id IS NULL OR a.tenant_id<>c.tenant_id")"
cross_tenant_children=0
for table in amazon_return_events amazon_return_evidence amazon_return_outbox amazon_return_overrides; do
    count="$(scalar "SELECT COUNT(*) FROM \`$table\` child LEFT JOIN amazon_return_cases parent ON parent.id=child.case_id WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id OR parent.amazon_connection_id<>child.amazon_connection_id")"
    cross_tenant_children=$((cross_tenant_children + count))
done
count="$(scalar "SELECT COUNT(*) FROM amazon_return_dead_letters child LEFT JOIN amazon_return_outbox parent ON parent.id=child.outbox_id WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id OR parent.amazon_connection_id<>child.amazon_connection_id OR parent.case_id<>child.case_id")"
cross_tenant_children=$((cross_tenant_children + count))
count="$(scalar "SELECT COUNT(*) FROM amazon_return_source_cursors child LEFT JOIN amazon_return_connections parent ON parent.id=child.amazon_connection_id WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id")"
cross_tenant_children=$((cross_tenant_children + count))
count="$(scalar "SELECT COUNT(*) FROM amazon_return_connections child LEFT JOIN amazon_return_tenants parent ON parent.id=child.tenant_id WHERE parent.id IS NULL")"
cross_tenant_children=$((cross_tenant_children + count))
count="$(scalar "SELECT COUNT(*) FROM amazon_return_policies child LEFT JOIN amazon_return_tenants parent ON parent.id=child.tenant_id WHERE parent.id IS NULL")"
cross_tenant_children=$((cross_tenant_children + count))
count="$(scalar "SELECT COUNT(*) FROM amazon_return_tenant_users child LEFT JOIN amazon_return_tenants parent ON parent.id=child.tenant_id WHERE parent.id IS NULL")"
cross_tenant_children=$((cross_tenant_children + count))
count="$(scalar "SELECT COUNT(*) FROM amazon_return_feature_flags child LEFT JOIN amazon_return_connections parent ON parent.id=child.amazon_connection_id WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id")"
cross_tenant_children=$((cross_tenant_children + count))
count="$(scalar "SELECT COUNT(*) FROM amazon_return_feature_flags flag JOIN amazon_return_tenant_users user ON user.id=flag.updated_by_user_id WHERE user.tenant_id<>flag.tenant_id")"
cross_tenant_children=$((cross_tenant_children + count))
cross_tenant_mismatch_count=$((cross_tenant_children + case_connection_mismatches))
processing_jobs="$(scalar "SELECT COUNT(*) FROM amazon_return_outbox WHERE tenant_id=$tenant_id AND amazon_connection_id=$connection_id AND status='PROCESSING'")"

[[ "$ownership_nulls" -eq 0 ]] || { echo "ownership_nulls=$ownership_nulls" >&2; exit 1; }
[[ "$cross_tenant_mismatch_count" -eq 0 ]] || { echo "cross_tenant_mismatch_count=$cross_tenant_mismatch_count" >&2; exit 1; }
[[ "$processing_jobs" -eq 0 ]] || { echo "processing_jobs=$processing_jobs" >&2; exit 1; }

env_value() {
    local key="$1"
    if [[ -n "$env_file" && -r "$env_file" ]]; then
        awk -F= -v wanted="$key" '$1==wanted{sub(/^[^=]*=/,"");print;exit}' "$env_file"
    else
        printf '%s' "${!key:-0}"
    fi
}
write_flags_disabled=true
for key in AMAZON_RETURNS_SAFE_T_WRITE AMAZON_RETURNS_APPEAL_WRITE AMAZON_RETURNS_EMAIL_REVIEW_WRITE AMAZON_RETURNS_SUPPORT_WRITE; do
    value="$(env_value "$key")"
    case "${value,,}" in ''|0|false|no|off) ;; *) write_flags_disabled=false ;; esac
done
[[ "$write_flags_disabled" == true ]] || { echo 'external_write_flag_enabled=true' >&2; exit 1; }

emit "tenant_count=$tenant_count"
emit "connection_count=$connection_count"
emit "target_current_cases=$target_current_cases"
emit "expected_cases=$expected_cases"
emit "ownership_nulls=$ownership_nulls"
emit "cross_tenant_children=$cross_tenant_children"
emit "case_connection_mismatches=$case_connection_mismatches"
emit "cross_tenant_mismatch_count=$cross_tenant_mismatch_count"
emit "processing_jobs=$processing_jobs"
emit "write_flags_disabled=$write_flags_disabled"
emit 'live_tenant_verification=ok'

if [[ -n "$output_file" ]]; then
    install -d -m 0700 "$(dirname "$output_file")"
    install -m 0600 "$summary_file" "$output_file"
fi
