#!/usr/bin/env bash
set -Eeuo pipefail

repo="${AMAZON_RETURNS_REPO:-/home/ubuntu/amazon-returns-safet}"
deploy_root="${AMAZON_RETURNS_DEPLOY_ROOT:-/home/ubuntu/amazon-returns-deploy}"
repo_name='Vivaliz-site/amazon-returns-safet'

[[ "$(id -u)" -eq 0 ]] || { echo 'auto-deploy requires root' >&2; exit 2; }
[[ -d "$repo/.git" ]] || { echo 'target checkout missing' >&2; exit 2; }
if ! runuser -u ubuntu -- git -C "$repo" diff --quiet || ! runuser -u ubuntu -- git -C "$repo" diff --cached --quiet; then
    echo 'auto_deploy_skipped=dirty_checkout'
    exit 0
fi

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

echo "auto_deploy_sha=$target_sha"
