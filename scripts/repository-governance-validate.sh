#!/usr/bin/env bash
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"
cd "$root"
exec bash scripts/absolute-audit-governance-validate.sh "${1:-manual}"
