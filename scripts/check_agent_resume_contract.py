#!/usr/bin/env python3
"""Fail closed when any agent entrypoint drops the Retome execution contract."""
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
ENTRYPOINTS = ("AGENTS.md", "CLAUDE.md", "GEMINI.md")
REQUIRED = ("RESUME_EXECUTION_GATE_V1", "Retome", "rules_loaded", "next_action",
            "RUNNING", "CONCLUIDO", "BLOCKED_EXTERNAL")
missing = []
for name in ENTRYPOINTS:
    path = ROOT / name
    if not path.is_file():
        missing.append(f"{name}: file missing")
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    missing.extend(f"{name}: {tag} missing" for tag in REQUIRED if tag not in text)
copilot = ROOT / ".github/copilot-instructions.md"
if copilot.is_file() and "AGENTS.md" not in copilot.read_text(encoding="utf-8", errors="replace"):
    missing.append("Copilot entrypoint must refer to AGENTS.md")
if missing:
    print("\n".join(missing), file=sys.stderr)
    sys.exit(2)
print("RESUME_EXECUTION_GATE_V1=PASS")
