#!/usr/bin/env python3
from __future__ import annotations

import os
import stat
import sys
from pathlib import Path

EXPECTED = {"OLIST_ERP_LOGIN_EMAIL", "OLIST_ERP_LOGIN_PASSWORD"}
MAX_BYTES = 4096
MAX_VALUE_BYTES = 1024


def fail(message: str) -> None:
    print(f"olist_login_env_validation=failed reason={message}", file=sys.stderr)
    raise SystemExit(2)


def main() -> None:
    if len(sys.argv) != 2:
        fail("usage")
    path = Path(sys.argv[1])
    try:
        st = path.lstat()
    except OSError:
        fail("unreadable")
    if stat.S_ISLNK(st.st_mode) or not stat.S_ISREG(st.st_mode):
        fail("invalid_file_type")
    if st.st_size < 1 or st.st_size > MAX_BYTES:
        fail("invalid_size")

    try:
        raw = path.read_bytes()
    except OSError:
        fail("unreadable")
    if b"\x00" in raw or b"\r" in raw:
        fail("invalid_bytes")
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        fail("invalid_utf8")

    values: dict[str, str] = {}
    for line in text.splitlines():
        if not line or line.startswith("#") or "=" not in line:
            fail("invalid_line")
        key, value = line.split("=", 1)
        if key not in EXPECTED or key in values:
            fail("unexpected_or_duplicate_key")
        if not value or len(value.encode("utf-8")) > MAX_VALUE_BYTES:
            fail("invalid_value")
        values[key] = value

    if set(values) != EXPECTED:
        fail("missing_key")
    print("olist_login_env_validation=ok")


if __name__ == "__main__":
    main()
