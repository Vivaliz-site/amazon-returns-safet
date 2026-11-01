#!/usr/bin/env bash
set -Eeuo pipefail
root="$(git rev-parse --show-toplevel)"
cd "$root"

# Human account sees all PRs via repository permissions, not blanket reviewer requests.
python3 scripts/check_pr_review_routing.py
python3 -m unittest tests/test_pr_review_routing.py -v
bash scripts/absolute-audit-governance-validate.sh "${1:-manual}"
