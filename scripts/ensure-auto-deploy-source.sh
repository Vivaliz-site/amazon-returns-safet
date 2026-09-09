#!/usr/bin/env bash
set -Eeuo pipefail

deploy_source="${AMAZON_RETURNS_DEPLOY_SOURCE_REPO:-/home/ubuntu/amazon-returns-deploy-source}"
repo_remote='git@github.com:Vivaliz-site/amazon-returns-safet.git'

[[ "$(id -u)" -eq 0 ]] || { echo 'deploy-source bootstrap requires root' >&2; exit 2; }

if [[ ! -e "$deploy_source" ]]; then
    runuser -u ubuntu -- git clone --quiet --branch main --single-branch "$repo_remote" "$deploy_source"
fi

[[ -d "$deploy_source/.git" ]] || { echo 'dedicated deploy source is not a git checkout' >&2; exit 2; }
[[ "$(stat -c '%U' "$deploy_source")" == 'ubuntu' ]] || { echo 'dedicated deploy source must be owned by ubuntu' >&2; exit 2; }
origin_url="$(runuser -u ubuntu -- git -C "$deploy_source" remote get-url origin)"
case "$origin_url" in
    git@github.com:Vivaliz-site/amazon-returns-safet.git|https://github.com/Vivaliz-site/amazon-returns-safet|https://github.com/Vivaliz-site/amazon-returns-safet.git) ;;
    *) echo 'dedicated deploy source has unexpected origin' >&2; exit 2 ;;
esac

if [[ -n "$(runuser -u ubuntu -- git -C "$deploy_source" status --porcelain)" ]]; then
    echo 'dedicated deploy source is dirty' >&2
    exit 2
fi

runuser -u ubuntu -- git -C "$deploy_source" fetch --quiet origin main
branch="$(runuser -u ubuntu -- git -C "$deploy_source" branch --show-current)"
if [[ "$branch" != 'main' ]]; then
    runuser -u ubuntu -- git -C "$deploy_source" checkout --quiet main
fi
runuser -u ubuntu -- git -C "$deploy_source" merge --ff-only --quiet origin/main

echo "deploy_source_ready=$deploy_source"
echo "deploy_source_sha=$(runuser -u ubuntu -- git -C "$deploy_source" rev-parse HEAD)"