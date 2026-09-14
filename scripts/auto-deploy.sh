#!/usr/bin/env bash
set -Eeuo pipefail

repo="${AMAZON_RETURNS_REPO:-/home/ubuntu/amazon-returns-deploy-source}"
deploy_root="${AMAZON_RETURNS_DEPLOY_ROOT:-/home/ubuntu/amazon-returns-deploy}"
repo_name='Vivaliz-site/amazon-returns-safet'
continuity_config='/etc/agent-continuity/config.json'

[[ "$(id -u)" -eq 0 ]] || { echo 'auto-deploy requires root' >&2; exit 2; }
[[ -d "$repo/.git" ]] || { echo 'target checkout missing' >&2; exit 2; }
if [[ -n "$(runuser -u ubuntu -- git -C "$repo" status --porcelain)" ]]; then
    echo auto_deploy_skipped=dirty_checkout
    exit 0
fi

bootstrap_continuity() {
    local template="$repo/deploy/continuity/config.production.json"
    local installer="$repo/scripts/install-continuity-controller.sh"
    local installed_service='/etc/systemd/system/agent-continuity-controller.service'
    local installed_timer='/etc/systemd/system/agent-continuity-controller.timer'
    local needs_install=0
    [[ -r "$template" && -x "$installer" ]] || return 0
    if [[ -e "$continuity_config" ]]; then
        [[ -f "$continuity_config" && ! -L "$continuity_config" ]] || {
            echo 'continuity_config_invalid_type=true' >&2
            return 2
        }
    else
        install -d -o root -g root -m 0750 "$(dirname "$continuity_config")"
        install -o root -g root -m 0640 "$template" "$continuity_config"
        needs_install=1
    fi
    [[ -f "$installed_service" ]] || needs_install=1
    [[ -f "$installed_timer" ]] || needs_install=1
    if [[ "$needs_install" -eq 0 ]] && ! cmp -s "$repo/deploy/systemd/agent-continuity-controller.service" "$installed_service"; then
        needs_install=1
    fi
    if [[ "$needs_install" -eq 0 ]] && ! cmp -s "$repo/deploy/systemd/agent-continuity-controller.timer" "$installed_timer"; then
        needs_install=1
    fi
    if [[ "$needs_install" -eq 1 ]]; then
        CONTINUITY_CONFIG="$continuity_config" "$installer"
        systemctl start agent-continuity-controller.service
    fi
}

bootstrap_continuity

runuser -u ubuntu -- git -C "$repo" fetch --quiet origin main
target_sha="$(runuser -u ubuntu -- git -C "$repo" rev-parse origin/main)"
deployed_sha="$(cat "$deploy_root/current/.release-sha" 2>/dev/null || true)"
if [[ "$target_sha" == "$deployed_sha" ]]; then
    echo 'auto_deploy_skipped=already_current'
    exit 0
fi

checks="$(runuser -u ubuntu -- gh api -H 'Accept: application/vnd.github+json' "/repos/$repo_name/commits/$target_sha/check-runs")"
if ! jq -e '.total_count > 0 and ([.check_runs[] | select(.status != "completed" or .conclusion != "success")] | length == 0)' >/dev/null <<<"$checks"; then
    echo 'auto_deploy_skipped=ci_not_green'
    exit 0
fi
runuser -u ubuntu -- git -C "$repo" merge --ff-only --quiet origin/main
AMAZON_RETURNS_REPO="$repo" \
AMAZON_RETURNS_DEPLOY_ROOT="$deploy_root" \
AMAZON_RETURNS_IMPORT_SOURCE=0 \
    "$repo/scripts/provision-production.sh"

bootstrap_continuity
echo "auto_deploy_sha=$target_sha"
