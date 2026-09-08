#!/usr/bin/env python3
import argparse
import base64
import hashlib
import hmac
import os
import stat
import struct
import sys
import time

STEP_SECONDS = 30
DIGITS = 6


def fail(message: str, code: int = 78) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(code)


def read_seed(path: str) -> bytes:
    if not path:
        fail('SEED_NOT_CONFIGURED')
    try:
        info = os.stat(path, follow_symlinks=False)
    except OSError:
        fail('SEED_NOT_CONFIGURED')
    mode = stat.S_IMODE(info.st_mode)
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode):
        fail('SEED_INVALID_FILE')
    if mode not in (0o400, 0o600):
        fail('SEED_PERMISSIONS_INVALID')
    try:
        with open(path, 'rt', encoding='ascii') as handle:
            raw = handle.read().strip().replace(' ', '')
    except (OSError, UnicodeError):
        fail('SEED_NOT_CONFIGURED')
    if not raw or any(ch not in 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567abcdefghijklmnopqrstuvwxyz' for ch in raw):
        fail('SEED_INVALID')
    padded = raw.upper() + '=' * ((8 - len(raw) % 8) % 8)
    try:
        seed = base64.b32decode(padded, casefold=True)
    except Exception:
        fail('SEED_INVALID')
    if len(seed) < 10:
        fail('SEED_INVALID')
    return seed


def totp(seed: bytes, unix_time: int) -> str:
    counter = int(unix_time) // STEP_SECONDS
    digest = hmac.new(seed, struct.pack('>Q', counter), hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    binary = struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF
    return f'{binary % (10 ** DIGITS):0{DIGITS}d}'


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--seed-file', required=True)
    parser.add_argument('--test-time', type=int, default=None, help=argparse.SUPPRESS)
    parser.add_argument('--test-only', action='store_true', help=argparse.SUPPRESS)
    args = parser.parse_args()
    if args.test_time is not None and not args.test_only:
        fail('TEST_TIME_REQUIRES_TEST_ONLY', 64)
    when = int(time.time()) if args.test_time is None else int(args.test_time)
    seed = read_seed(args.seed_file)
    sys.stdout.write(totp(seed, when) + '\n')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())