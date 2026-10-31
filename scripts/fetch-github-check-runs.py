#!/usr/bin/env python3
"""Fetch commit check-runs without requiring GitHub CLI state on production hosts."""

from __future__ import annotations

import json
import os
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


def main() -> int:
    if len(sys.argv) != 3:
        print("usage: fetch-github-check-runs.py OWNER/REPO COMMIT_SHA", file=sys.stderr)
        return 2

    repository, commit_sha = sys.argv[1:]
    if "/" not in repository or not commit_sha:
        print("invalid repository or commit", file=sys.stderr)
        return 2

    request = Request(
        f"https://api.github.com/repos/{repository}/commits/{commit_sha}/check-runs",
        headers={
            "Accept": "application/vnd.github+json",
            "User-Agent": "ShopVivaliz-amazon-returns-auto-deploy",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    token = os.environ.get("GITHUB_TOKEN", "").strip() or os.environ.get("GH_TOKEN", "").strip()
    if token:
        request.add_header("Authorization", f"Bearer {token}")

    try:
        with urlopen(request, timeout=20) as response:
            payload = json.load(response)
    except HTTPError as exc:
        print(f"github_check_runs_http_error={exc.code}", file=sys.stderr)
        return 10
    except URLError as exc:
        print(f"github_check_runs_network_error={exc.reason}", file=sys.stderr)
        return 11
    except TimeoutError:
        print("github_check_runs_timeout=true", file=sys.stderr)
        return 12

    if not isinstance(payload, dict) or not isinstance(payload.get("check_runs"), list):
        print("github_check_runs_invalid_response=true", file=sys.stderr)
        return 13

    json.dump(payload, sys.stdout, separators=(",", ":"))
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
