#!/usr/bin/env bash
set -Eeuo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo 'verify-migration requires root' >&2; exit 2; }
source_db="${AMAZON_RETURNS_SOURCE_DB:-shopvivaliz}"
target_db="${AMAZON_RETURNS_TARGET_DB:-amazon_returns_safet}"
tenant_slug="${AMAZON_RETURNS_TENANT_SLUG:-shopvivaliz}"
connection_key="${AMAZON_RETURNS_CONNECTION_KEY:-amazon-br-primary}"
expected_cases="${AMAZON_RETURNS_EXPECTED_CASES:-37}"
output_file="${AMAZON_RETURNS_VERIFICATION_OUTPUT:-}"
env_file="${AMAZON_RETURNS_ENV_FILE:-}"

[[ "$source_db" =~ ^[A-Za-z0-9_]+$ ]] || { echo 'invalid source database name' >&2; exit 2; }
[[ "$target_db" =~ ^[A-Za-z0-9_]+$ ]] || { echo 'invalid target database name' >&2; exit 2; }
[[ "$tenant_slug" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid tenant slug' >&2; exit 2; }
[[ "$connection_key" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid connection key' >&2; exit 2; }
[[ "$expected_cases" =~ ^[1-9][0-9]*$ ]] || { echo 'invalid expected case count' >&2; exit 2; }
if [[ -n "$output_file" ]]; then
    output_dir="$(dirname "$output_file")"
    install -d -m 0700 "$output_dir"
fi

summary_file="$(mktemp)"
pre_manifest="$(mktemp)"
post_manifest="$(mktemp)"
owner_manifest="$(mktemp)"
cleanup() { rm -f "$summary_file" "$pre_manifest" "$post_manifest" "$owner_manifest"; }
trap cleanup EXIT
emit() { printf '%s\n' "$*" | tee -a "$summary_file"; }

mysql_scalar() {
    local db="$1" sql="$2"
    mysql --protocol=socket -uroot --batch --skip-column-names "$db" -e "$sql"
}
row_count() {
    local db="$1" table="$2" where_clause="${3:-}"
    mysql_scalar "$db" "SELECT COUNT(*) FROM \`$table\` $where_clause"
}

tenant_id="$(mysql_scalar "$target_db" "SELECT id FROM amazon_return_tenants WHERE slug='$tenant_slug' AND status='ACTIVE' LIMIT 1")"
[[ "$tenant_id" =~ ^[0-9]+$ ]] || { echo 'active tenant not found' >&2; exit 1; }
connection_id="$(mysql_scalar "$target_db" "SELECT id FROM amazon_return_connections WHERE tenant_id=$tenant_id AND connection_key='$connection_key' AND status='ACTIVE' LIMIT 1")"
[[ "$connection_id" =~ ^[0-9]+$ ]] || { echo 'active tenant connection not found' >&2; exit 1; }
tenant_count="$(mysql_scalar "$target_db" "SELECT COUNT(*) FROM amazon_return_tenants WHERE slug='$tenant_slug' AND status='ACTIVE'")"
connection_count="$(mysql_scalar "$target_db" "SELECT COUNT(*) FROM amazon_return_connections WHERE tenant_id=$tenant_id AND connection_key='$connection_key' AND status='ACTIVE'")"
[[ "$tenant_count" -eq 1 ]] || { echo "tenant_count=$tenant_count" >&2; exit 1; }
[[ "$connection_count" -eq 1 ]] || { echo "connection_count=$connection_count" >&2; exit 1; }

tables=(
    amazon_return_cases
    amazon_return_events
    amazon_return_outbox
    amazon_return_dead_letters
    amazon_return_evidence
    amazon_return_policies
    amazon_return_source_cursors
    amazon_return_overrides
)
declare -A connection_owned=(
    [amazon_return_cases]=1
    [amazon_return_events]=1
    [amazon_return_outbox]=1
    [amazon_return_dead_letters]=1
    [amazon_return_evidence]=1
    [amazon_return_source_cursors]=1
    [amazon_return_overrides]=1
)

common_projection() {
    local table="$1"
    mysql --protocol=socket -uroot --batch --skip-column-names information_schema <<SQL
SET SESSION group_concat_max_len=1048576;
SELECT GROUP_CONCAT(
    CONCAT(
        'IF(\`', source_columns.COLUMN_NAME,
        '\` IS NULL,''N'',CONCAT(''V'',HEX(CAST(\`', source_columns.COLUMN_NAME,
        '\` AS BINARY))))'
    )
    ORDER BY source_columns.ORDINAL_POSITION SEPARATOR ','
)
FROM information_schema.columns source_columns
JOIN information_schema.columns target_columns
  ON target_columns.TABLE_SCHEMA='$target_db'
 AND target_columns.TABLE_NAME=source_columns.TABLE_NAME
 AND target_columns.COLUMN_NAME=source_columns.COLUMN_NAME
WHERE source_columns.TABLE_SCHEMA='$source_db'
  AND source_columns.TABLE_NAME='$table'
  AND source_columns.COLUMN_NAME NOT IN ('tenant_id','amazon_connection_id');
SQL
}

owner_projection() {
    local table="$1"
    mysql --protocol=socket -uroot --batch --skip-column-names information_schema <<SQL
SET SESSION group_concat_max_len=1048576;
SELECT GROUP_CONCAT(
    CONCAT(
        'IF(\`', COLUMN_NAME,
        '\` IS NULL,''N'',CONCAT(''V'',HEX(CAST(\`', COLUMN_NAME,
        '\` AS BINARY))))'
    )
    ORDER BY ORDINAL_POSITION SEPARATOR ','
)
FROM information_schema.columns
WHERE TABLE_SCHEMA='$target_db' AND TABLE_NAME='$table';
SQL
}

table_hash() {
    local db="$1" table="$2" where_clause="$3" projection="$4"
    [[ -n "$projection" ]] || { echo "empty canonical projection for $table" >&2; return 1; }
    mysql --protocol=socket -uroot --batch --raw --skip-column-names "$db" \
        -e "SELECT CONCAT_WS('|',$projection) FROM \`$table\` $where_clause ORDER BY \`id\`" \
        | sha256sum | awk '{print $1}'
}

canonical_owner_rows() {
    local table="$1" where_clause="$2" projection
    projection="$(owner_projection "$table")"
    table_hash "$target_db" "$table" "$where_clause" "$projection"
}

for table in "${tables[@]}"; do
    projection="$(common_projection "$table")"
    source_count="$(row_count "$source_db" "$table")"
    if [[ -n "${connection_owned[$table]:-}" ]]; then
        target_where="WHERE tenant_id=$tenant_id AND amazon_connection_id=$connection_id"
    else
        target_where="WHERE tenant_id=$tenant_id"
    fi
    target_count="$(row_count "$target_db" "$table" "$target_where")"
    [[ "$source_count" == "$target_count" ]] || {
        echo "migration_count_mismatch table=$table source=$source_count target=$target_count" >&2
        exit 1
    }
    source_hash="$(table_hash "$source_db" "$table" '' "$projection")"
    target_hash="$(table_hash "$target_db" "$table" "$target_where" "$projection")"
    [[ "$source_hash" == "$target_hash" ]] || {
        echo "migration_hash_mismatch table=$table source=$source_hash target=$target_hash" >&2
        exit 1
    }
    owner_hash="$(canonical_owner_rows "$table" "$target_where")"
    printf '%s|%s|%s\n' "$table" "$source_count" "$source_hash" >> "$pre_manifest"
    printf '%s|%s|%s\n' "$table" "$target_count" "$target_hash" >> "$post_manifest"
    printf '%s|%s|%s\n' "$table" "$target_count" "$owner_hash" >> "$owner_manifest"
    emit "migration_table_ok table=$table tenant_id=$tenant_id connection_id=$connection_id rows=$source_count pre_sha256=$source_hash post_sha256=$target_hash owner_sha256=$owner_hash"
done

pre_migration_hash="$(sha256sum "$pre_manifest" | awk '{print $1}')"
post_migration_hash="$(sha256sum "$post_manifest" | awk '{print $1}')"
ownership_hash="$(sha256sum "$owner_manifest" | awk '{print $1}')"
[[ "$pre_migration_hash" == "$post_migration_hash" ]] || {
    echo "aggregate_hash_mismatch pre=$pre_migration_hash post=$post_migration_hash" >&2
    exit 1
}

source_current_cases="$(row_count "$source_db" amazon_return_cases)"
target_current_cases="$(row_count "$target_db" amazon_return_cases "WHERE tenant_id=$tenant_id AND amazon_connection_id=$connection_id")"
[[ "$source_current_cases" -eq "$expected_cases" ]] || {
    echo "source_case_count_unexpected expected=$expected_cases actual=$source_current_cases" >&2
    exit 1
}
[[ "$target_current_cases" -eq "$expected_cases" ]] || {
    echo "target_case_count_unexpected expected=$expected_cases actual=$target_current_cases" >&2
    exit 1
}

ownership_nulls=0
for table in "${tables[@]}"; do
    null_where='WHERE tenant_id IS NULL'
    if [[ -n "${connection_owned[$table]:-}" ]]; then
        null_where='WHERE tenant_id IS NULL OR amazon_connection_id IS NULL'
    fi
    null_count="$(row_count "$target_db" "$table" "$null_where")"
    ownership_nulls=$((ownership_nulls + null_count))
done
case_connection_mismatches="$(mysql_scalar "$target_db" \
    "SELECT COUNT(*) FROM amazon_return_cases c LEFT JOIN amazon_return_connections a ON a.id=c.amazon_connection_id WHERE a.id IS NULL OR a.tenant_id<>c.tenant_id")"

cross_tenant_children=0
for table in amazon_return_events amazon_return_evidence amazon_return_outbox amazon_return_overrides; do
    mismatch="$(mysql_scalar "$target_db" \
        "SELECT COUNT(*) FROM \`$table\` child LEFT JOIN amazon_return_cases parent ON parent.id=child.case_id WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id OR parent.amazon_connection_id<>child.amazon_connection_id")"
    cross_tenant_children=$((cross_tenant_children + mismatch))
done
mismatch="$(mysql_scalar "$target_db" \
    "SELECT COUNT(*) FROM amazon_return_dead_letters child LEFT JOIN amazon_return_outbox parent ON parent.id=child.outbox_id WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id OR parent.amazon_connection_id<>child.amazon_connection_id OR parent.case_id<>child.case_id")"
cross_tenant_children=$((cross_tenant_children + mismatch))
mismatch="$(mysql_scalar "$target_db" \
    "SELECT COUNT(*) FROM amazon_return_source_cursors child LEFT JOIN amazon_return_connections parent ON parent.id=child.amazon_connection_id WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id")"
cross_tenant_children=$((cross_tenant_children + mismatch))
cross_tenant_mismatch_count=$((cross_tenant_children + case_connection_mismatches))

[[ "$ownership_nulls" -eq 0 ]] || { echo "ownership_nulls=$ownership_nulls" >&2; exit 1; }
[[ "$cross_tenant_children" -eq 0 ]] || { echo "cross_tenant_children=$cross_tenant_children" >&2; exit 1; }
[[ "$case_connection_mismatches" -eq 0 ]] || { echo "case_connection_mismatches=$case_connection_mismatches" >&2; exit 1; }

policy_sql="$(php "$(dirname "$0")/policy-matrix-check.php" --sql "$tenant_id")"
bad_policy="$(mysql_scalar "$target_db" "$policy_sql")"
[[ "$bad_policy" -eq 0 ]] || { echo "migration_policy_matrix_mismatch count=$bad_policy" >&2; exit 1; }
processing_jobs="$(mysql_scalar "$target_db" "SELECT COUNT(*) FROM amazon_return_outbox WHERE tenant_id=$tenant_id AND amazon_connection_id=$connection_id AND status='PROCESSING'")"
[[ "$processing_jobs" -eq 0 ]] || { echo "processing_jobs=$processing_jobs" >&2; exit 1; }

env_value() {
    local key="$1" value=''
    if [[ -n "$env_file" && -r "$env_file" ]]; then
        value="$(awk -F= -v wanted="$key" '$1==wanted{sub(/^[^=]*=/,"");print;exit}' "$env_file")"
    else
        value="${!key:-0}"
    fi
    printf '%s' "$value"
}
write_flags_disabled=true
for key in \
    AMAZON_RETURNS_SAFE_T_WRITE \
    AMAZON_RETURNS_APPEAL_WRITE \
    AMAZON_RETURNS_EMAIL_REVIEW_WRITE \
    AMAZON_RETURNS_SUPPORT_WRITE; do
    value="$(env_value "$key")"
    case "${value,,}" in
        ''|0|false|no|off) ;;
        *) write_flags_disabled=false ;;
    esac
done
[[ "$write_flags_disabled" == true ]] || { echo 'external_write_flag_enabled=true' >&2; exit 1; }

emit "tenant_count=$tenant_count"
emit "connection_count=$connection_count"
emit "source_current_cases=$source_current_cases"
emit "target_current_cases=$target_current_cases"
emit "expected_cases=$expected_cases"
emit "ownership_nulls=$ownership_nulls"
emit "cross_tenant_children=$cross_tenant_children"
emit "case_connection_mismatches=$case_connection_mismatches"
emit "cross_tenant_mismatch_count=$cross_tenant_mismatch_count"
emit "processing_jobs=$processing_jobs"
emit "pre_migration_hash=$pre_migration_hash"
emit "post_migration_hash=$post_migration_hash"
emit "ownership_hash=$ownership_hash"
emit "write_flags_disabled=$write_flags_disabled"
emit "migration_policy_ok tenant_id=$tenant_id policy_matrix=BR_45_60"
emit 'migration_verification=ok'

if [[ -n "$output_file" ]]; then
    install -m 0600 "$summary_file" "$output_file"
fi
