#!/usr/bin/env bash
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"
cd "$root"
bash scripts/absolute-audit-governance-validate.sh "${1:-manual}"
