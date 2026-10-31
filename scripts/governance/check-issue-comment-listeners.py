#!/usr/bin/env python3
"""Guard: at most one active direct `issue_comment` GitHub Actions listener.

Scans .github/workflows/*.yml|*.yaml (skipping anything disabled or archived)
and counts workflows that declare `issue_comment` as a *direct top-level*
trigger under `on:` (i.e. `on: issue_comment`, `on: [issue_comment, ...]`, or
`on:\n  issue_comment:` / `on:\n  - issue_comment`).

A workflow that merely mentions `issue_comment` elsewhere (comments, job
steps, nested workflow_call inputs, etc.) does not count — only a direct
top-level trigger does, since that is what makes a workflow an active
listener for `issue_comment` events.

This is a minimal, dependency-free line/indentation scanner rather than a
full YAML parser, to avoid requiring a YAML library in CI. It is written
conservatively: when in doubt about a workflow's shape, it should not miss a
real direct top-level issue_comment trigger.

Exit code: 0 if the count of active direct issue_comment listeners is <= 1,
1 otherwise (including when scanning fails to find the workflows directory,
which is treated as 0 listeners and therefore a pass).

Usage:
    check-issue-comment-listeners.py [workflows_dir]

If no directory is given, defaults to `.github/workflows` under the current
git repository root (or CWD if not in a git repo).
"""

from __future__ import annotations

import subprocess
import sys
from pathlib import Path


def default_workflows_dir() -> Path:
    try:
        root = subprocess.run(
            ["git", "rev-parse", "--show-toplevel"],
            check=True,
            capture_output=True,
            text=True,
        ).stdout.strip()
        base = Path(root)
    except Exception:
        base = Path.cwd()
    return base / ".github" / "workflows"


def is_skipped(path: Path) -> bool:
    name = path.name.lower()
    if ".disabled" in name:
        return True
    parts_lower = [p.lower() for p in path.parts]
    for marker in ("archive", "archived", "historical"):
        if any(marker in part for part in parts_lower):
            return True
    return False


def indent_of(line: str) -> int:
    return len(line) - len(line.lstrip(" "))


def strip_comment(line: str) -> str:
    # Best-effort: drop a trailing `# ...` comment that is not inside quotes.
    in_single = False
    in_double = False
    for i, ch in enumerate(line):
        if ch == "'" and not in_double:
            in_single = not in_single
        elif ch == '"' and not in_single:
            in_double = not in_double
        elif ch == "#" and not in_single and not in_double:
            return line[:i]
    return line


def workflow_has_direct_issue_comment_trigger(text: str) -> bool:
    raw_lines = text.splitlines()
    lines = [strip_comment(line).rstrip("\n") for line in raw_lines]

    on_line_idx = None
    on_indent = None
    for idx, line in enumerate(lines):
        stripped = line.strip()
        if stripped == "":
            continue
        if indent_of(line) != 0:
            continue
        if line == "on:" or line.startswith("on:"):
            # Only match a real top-level `on:` mapping key, not e.g. `only-on:`.
            key_part = line.split(":", 1)[0]
            if key_part.strip() != "on":
                continue
            on_line_idx = idx
            on_indent = 0
            inline_value = line.split(":", 1)[1].strip()
            break

    if on_line_idx is None:
        # YAML also allows the boolean-looking key `on` to appear quoted or
        # as `"on":` / `'on':`; handle those forms too.
        for idx, line in enumerate(lines):
            if indent_of(line) != 0:
                continue
            stripped = line.strip()
            for quote in ('"on"', "'on'"):
                if stripped.startswith(quote + ":"):
                    on_line_idx = idx
                    inline_value = stripped[len(quote) + 1 :].strip()
                    break
            if on_line_idx is not None:
                break

    if on_line_idx is None:
        return False

    # Inline scalar/flow form on the `on:` line itself, e.g.:
    #   on: issue_comment
    #   on: [push, issue_comment]
    #   on: {push, issue_comment}
    if inline_value:
        if _flow_value_contains_issue_comment(inline_value):
            return True
        # Inline value present and not a block continuation marker -> no
        # further block to scan (a scalar/flow value can't also open a
        # nested block on subsequent lines).
        return False

    # Block form: scan subsequent lines that are indented relative to `on:`
    # until a line at indent 0 (a sibling top-level key) is hit.
    block_indent = None
    idx = on_line_idx + 1
    while idx < len(lines):
        line = lines[idx]
        stripped = line.strip()
        if stripped == "":
            idx += 1
            continue
        cur_indent = indent_of(line)
        if cur_indent == 0:
            break
        if block_indent is None:
            block_indent = cur_indent
        if cur_indent != block_indent:
            idx += 1
            continue

        # Block sequence form:  - issue_comment
        if stripped.startswith("- "):
            item = stripped[2:].strip()
            if item == "issue_comment" or item.startswith("issue_comment:"):
                return True
        else:
            # Block mapping form: issue_comment: ...
            key = stripped.split(":", 1)[0].strip().strip("'\"")
            if key == "issue_comment":
                return True
        idx += 1

    return False


def _flow_value_contains_issue_comment(value: str) -> bool:
    value = value.strip()
    if value.startswith("[") and value.endswith("]"):
        inner = value[1:-1]
    elif value.startswith("{") and value.endswith("}"):
        inner = value[1:-1]
    else:
        inner = value
    tokens = [t.strip().strip("'\"") for t in inner.split(",")]
    for token in tokens:
        name = token.split(":", 1)[0].strip()
        if name == "issue_comment":
            return True
    return False


def main(argv: list[str]) -> int:
    workflows_dir = Path(argv[1]) if len(argv) > 1 else default_workflows_dir()

    if not workflows_dir.is_dir():
        print(f"No workflows directory found at {workflows_dir}; treating as 0 listeners.")
        print("issue_comment_listener_count=0")
        return 0

    candidates = sorted(
        p
        for p in workflows_dir.iterdir()
        if p.is_file() and p.suffix.lower() in (".yml", ".yaml")
    )

    matches: list[Path] = []
    for path in candidates:
        if is_skipped(path):
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except OSError as exc:
            print(f"warning: could not read {path}: {exc}", file=sys.stderr)
            continue
        if workflow_has_direct_issue_comment_trigger(text):
            matches.append(path)

    print("Active direct issue_comment listeners found:")
    if matches:
        for path in matches:
            print(f"  - {path}")
    else:
        print("  (none)")
    print(f"issue_comment_listener_count={len(matches)}")

    if len(matches) > 1:
        print(
            f"FAIL: {len(matches)} workflows declare issue_comment as a direct top-level "
            "trigger; at most 1 is allowed.",
            file=sys.stderr,
        )
        return 1

    print("PASS: issue_comment listener count is within the allowed limit (<= 1).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
