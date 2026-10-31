#!/usr/bin/env python3
"""Fail-closed selection of the suites relevant to an Amazon Returns PR.

Only known isolated surfaces may omit unrelated suites. Shared PHP/JS,
security, dependencies, workflows and policy changes always use the full gate.
"""
from __future__ import annotations

import argparse
from fnmatch import fnmatchcase
import json
import re
import subprocess
from pathlib import Path

SHA = re.compile(r"^[0-9a-f]{40}$")
ROOT_POLICY = {
    "AGENTS.md", "AUDIT_POLICY.md", "AGENTS-VM-ACCESS.md",
    "REGRAS-AGENTES-CENTRALIZADAS.md", "EXECUTION-PROVENANCE-POLICY.md",
    "docs/REGRAS-DE-ENTREGA.md", "docs/MEMORIA-DO-PROJETO.md",
}
ISOLATED = {
    "totp": {
        "scripts/amazon-returns/totp-current.py",
        "tests/totp-current.test.py",
    },
}
CONTINUITY_PREFIXES = ("tools/continuity/", "tests/continuity/")
CONTINUITY_FILES = {
    "scripts/migrate-continuity-config.py",
    "scripts/migrate-continuity-state-layout.py",
    "scripts/enable-continuity-auto-dispatch.py",
    "scripts/prepare-continuity-runtime-config.py",
    "scripts/run-continuity-pilot.py",
    "scripts/run-continuity-pilot.sh",
    "scripts/install-continuity-controller.sh",
    "scripts/provision-continuity-gemini-env.sh",
    "scripts/provision-continuity-publisher-key.sh",
}


def classify(paths: list[str] | None, force_full: bool = False) -> dict[str, object]:
    files = sorted(set(p.strip().replace("\\", "/").removeprefix("./")
                       for p in paths or [] if p.strip()))
    groups: set[str] = set()
    unsafe: list[str] = []
    if force_full or not files:
        unsafe.append("push_or_unknown_diff")
    for p in files:
        if p.startswith("/") or ".." in p.split("/") or "//" in p:
            unsafe.append(p)
        elif p in ROOT_POLICY or p.startswith(("docs/quality/", ".github/", "deploy/")):
            unsafe.append(p)
        elif p in ISOLATED["totp"]:
            groups.add("totp")
        elif p.startswith(CONTINUITY_PREFIXES) or p in CONTINUITY_FILES:
            groups.add("continuity")
        elif fnmatchcase(p, "tests/*.test.mjs"):
            groups.add("node")
        elif p == "README.md" or (p.startswith("docs/") and p.endswith(".md")
                                  and not p.startswith("docs/runbooks/")):
            groups.add("docs")
        else:
            unsafe.append(p)
    full = bool(unsafe)
    return {
        "mode": "full" if full else "scoped",
        "groups": ["full"] if full else sorted(groups),
        "files": files,
        "full_reasons": sorted(set(unsafe)),
        "php": full,
        "node": full or "node" in groups,
        "totp": full or "totp" in groups,
        "continuity": full or "continuity" in groups,
    }


def read_diff(base: str, head: str) -> list[str] | None:
    if not SHA.fullmatch(base) or not SHA.fullmatch(head):
        return None
    try:
        data = subprocess.run(
            ["git", "diff", "--name-only", "-z", f"{base}...{head}"],
            check=True, capture_output=True, timeout=30,
        ).stdout
        return [part.decode("utf-8", "replace") for part in data.split(b"\x00") if part]
    except (OSError, subprocess.SubprocessError):
        return None


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="")
    parser.add_argument("--head", default="")
    parser.add_argument("--force-full", action="store_true")
    parser.add_argument("--file", action="append")
    parser.add_argument("--github-output", default="")
    args = parser.parse_args()
    files = args.file if args.file is not None else read_diff(args.base, args.head)
    result = classify(files, force_full=args.force_full or files is None)
    if args.github_output:
        with Path(args.github_output).open("a", encoding="utf-8") as output:
            output.write(f"mode={result['mode']}\n")
            output.write(f"groups={','.join(result['groups'])}\n")
            for group in ("php", "node", "totp", "continuity"):
                output.write(f"{group}={str(result[group]).lower()}\n")
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
