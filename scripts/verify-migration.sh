#!/usr/bin/env bash
set -Eeuo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo 'verify-migration requires root' >&2; exit 2; }
source_db="${AMAZON_RETURNS_SOURCE_DB:-shopvivaliz}"
target_db="${AMAZON_RETURNS_TARGET_DB:-amazon_returns_safet}"
tables=(
    amazon_return_cases
    amazon_return_events
    amazon_return_outbox
    amazon_return_dead_letters
    amazon_return_evidence
    amazon_return_source_cursors
    amazon_return_overrides
)

row_count() {
    local db="$1" table="$2"
    mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM \`$table\`" "$db"
}

table_hash() {
    local db="$1" table="$2"
    mysqldump --protocol=socket -uroot --single-transaction --quick --skip-lock-tables \
        --no-create-info --skip-triggers --compact --skip-comments --skip-extended-insert \
        --order-by-primary "$db" "$table" | sha256sum | awk '{print $1}'
}
for table in "${tables[@]}"; do
    source_count="$(row_count "$source_db" "$table")"
    target_count="$(row_count "$target_db" "$table")"
    [[ "$source_count" == "$target_count" ]] || {
        echo "migration_count_mismatch table=$table source=$source_count target=$target_count" >&2
        exit 1
    }
    source_hash="$(table_hash "$source_db" "$table")"
    target_hash="$(table_hash "$target_db" "$table")"
    [[ "$source_hash" == "$target_hash" ]] || {
        echo "migration_hash_mismatch table=$table source=$source_hash target=$target_hash" >&2
        exit 1
    }
    echo "migration_table_ok table=$table rows=$source_count sha256=$source_hash"
done

source_policy_count="$(row_count "$source_db" amazon_return_policies)"
target_policy_count="$(row_count "$target_db" amazon_return_policies)"
[[ "$source_policy_count" == "$target_policy_count" ]] || {
    echo "migration_count_mismatch table=amazon_return_policies source=$source_policy_count target=$target_policy_count" >&2
    exit 1
}
bad_policy="$(mysql --protocol=socket -uroot -Nse "SELECT COUNT(*) FROM amazon_return_policies WHERE status='ACTIVE' AND eligibility_days <> 75" "$target_db")"
[[ "$bad_policy" == 0 ]] || { echo "migration_policy_not_d75 count=$bad_policy" >&2; exit 1; }
echo "migration_table_ok table=amazon_return_policies rows=$target_policy_count policy_d75=true"
echo 'migration_verification=ok'
