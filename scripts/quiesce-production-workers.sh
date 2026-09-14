#!/usr/bin/env bash
set -Eeuo pipefail

qw_scalar() {
    local target_db="$1" sql="$2"
    mysql --protocol=socket -uroot --batch --skip-column-names "$target_db" -e "$sql"
}

qw_processing_count() {
    local target_db="$1" tenant_id="$2" connection_id="$3"
    qw_scalar "$target_db" "SELECT COUNT(*) FROM amazon_return_outbox WHERE tenant_id=$tenant_id AND amazon_connection_id=$connection_id AND status='PROCESSING'"
}

qw_thaw_units() {
    local unit
    for unit in "$@"; do
        systemctl thaw "$unit" >/dev/null 2>&1 || true
    done
}

qw_unit_running() {
    local unit="$1" state
    state="$(systemctl show --property=ActiveState --value "$unit" 2>/dev/null || true)"
    case "$state" in
        active|activating|reloading|deactivating) return 0 ;;
        inactive|failed) return 1 ;;
    esac
    systemctl is-active --quiet "$unit"
}

quiesce_workers() {
    local target_db="$1" tenant_slug="$2" connection_key="$3"
    local timeout_seconds="${AMAZON_RETURNS_QUIESCE_TIMEOUT_SECONDS:-180}"
    local poll_seconds="${AMAZON_RETURNS_QUIESCE_POLL_SECONDS:-1}"
    local browser_timer='amazon-returns-seller-central-browser.timer'
    local -a services=('amazon-returns-safet.service' 'amazon-returns-seller-central-browser.service')
    [[ "$target_db" =~ ^[A-Za-z0-9_]+$ ]] || { echo 'invalid target database name' >&2; return 2; }
    [[ "$tenant_slug" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid tenant slug' >&2; return 2; }
    [[ "$connection_key" =~ ^[a-z0-9][a-z0-9-]{0,95}$ ]] || { echo 'invalid connection key' >&2; return 2; }
    [[ "$timeout_seconds" =~ ^[1-9][0-9]*$ ]] || { echo 'invalid quiesce timeout' >&2; return 2; }
    [[ "$poll_seconds" =~ ^[1-9][0-9]*$ ]] || { echo 'invalid quiesce poll interval' >&2; return 2; }

    local tenant_id connection_id count deadline unit timer_was_active=0
    local -a frozen=()
    tenant_id="$(qw_scalar "$target_db" "SELECT id FROM amazon_return_tenants WHERE slug='$tenant_slug' AND status='ACTIVE' LIMIT 1")"
    [[ "$tenant_id" =~ ^[0-9]+$ ]] || { echo 'active tenant not found during quiesce' >&2; return 1; }
    connection_id="$(qw_scalar "$target_db" "SELECT id FROM amazon_return_connections WHERE tenant_id=$tenant_id AND connection_key='$connection_key' AND status='ACTIVE' LIMIT 1")"
    [[ "$connection_id" =~ ^[0-9]+$ ]] || { echo 'active connection not found during quiesce' >&2; return 1; }

    if systemctl is-active --quiet "$browser_timer"; then
        timer_was_active=1
        systemctl stop "$browser_timer"
    fi
    deadline=$((SECONDS + timeout_seconds))

    while (( SECONDS <= deadline )); do
        count="$(qw_processing_count "$target_db" "$tenant_id" "$connection_id")"
        [[ "$count" =~ ^[0-9]+$ ]] || { echo 'invalid processing job count' >&2; ((timer_was_active)) && systemctl start "$browser_timer"; return 1; }
        if (( count != 0 )); then
            sleep "$poll_seconds"
            continue
        fi
        frozen=()
        for unit in "${services[@]}"; do
            if qw_unit_running "$unit"; then
                if ! systemctl freeze "$unit" >/dev/null; then
                    qw_thaw_units "${frozen[@]}"
                    ((timer_was_active)) && systemctl start "$browser_timer"
                    echo "worker_quiesce_freeze_failed=$unit" >&2
                    return 1
                fi
                frozen+=("$unit")
            fi
        done

        count="$(qw_processing_count "$target_db" "$tenant_id" "$connection_id")"
        if [[ ! "$count" =~ ^[0-9]+$ ]]; then
            qw_thaw_units "${frozen[@]}"
            ((timer_was_active)) && systemctl start "$browser_timer"
            echo 'invalid processing job count after freeze' >&2
            return 1
        fi
        if (( count != 0 )); then
            qw_thaw_units "${frozen[@]}"
            frozen=()
            sleep "$poll_seconds"
            continue
        fi

        for unit in "${frozen[@]}"; do
            systemctl stop "$unit"
        done
        frozen=()
        count="$(qw_processing_count "$target_db" "$tenant_id" "$connection_id")"
        if [[ "$count" != '0' ]]; then
            systemctl start amazon-returns-safet.service >/dev/null 2>&1 || true
            ((timer_was_active)) && systemctl start "$browser_timer" >/dev/null 2>&1 || true
            echo "worker_quiesce_processing_after_stop=$count" >&2
            return 1
        fi
        printf 'worker_quiesce=stopped processing_jobs=0 browser_timer_was_active=%s\n' "$timer_was_active"
        return 0
    done

    qw_thaw_units "${frozen[@]}"
    ((timer_was_active)) && systemctl start "$browser_timer"
    echo "worker_quiesce_timeout=${timeout_seconds}s" >&2
    return 1
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
    [[ "$(id -u)" -eq 0 ]] || { echo 'quiesce-production-workers requires root' >&2; exit 2; }
    [[ "$#" -eq 3 ]] || { echo 'usage: quiesce-production-workers.sh TARGET_DB TENANT_SLUG CONNECTION_KEY' >&2; exit 2; }
    quiesce_workers "$1" "$2" "$3"
fi
