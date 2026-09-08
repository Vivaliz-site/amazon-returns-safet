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

class TotpError(Exception):
    pass

def read_seed(path: str) -> bytes:
    try:
        info = os.stat(path, follow_symlinks=False)
    except FileNotFoundError as exc:
        raise TotpError('SEED_NOT_CONFIGURED') from exc
    if not stat.S_ISREG(info.st_mode):
        raise TotpError('seed file is not regular')
    if stat.S_IMODE(info.st_mode) & 0o007:
        raise TotpError('seed file permissions are too broad')
    try:
        with open(path, 'r', encoding='ascii') as handle:
            raw = handle.read().strip().replace(' ', '')
    except OSError as exc:
        raise TotpError('seed file unavailable') from exc
    if not raw:
        raise TotpError('SEED_NOT_CONFIGURED')
    padded = raw.upper() + ('=' * ((8 - len(raw) % 8) % 8))
    try:
        seed = base64.b32decode(padded, casefold=True)
    except Exception as exc:
        raise TotpError('seed is not valid Base32') from exc
    if len(seed) < 10:
        raise TotpError('seed is too short')
    return seed

def totp(seed: bytes, unix_time: int) -> str:
    counter = int(unix_time) // STEP_SECONDS
    message = struct.pack('>Q', counter)
    digest = hmac.new(seed, message, hashlib.sha1).digest()
    offset = digest[-1] & 0x0F
    binary = struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7FFFFFFF
    return f'{binary % (10 ** DIGITS):0{DIGITS}d}'

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--seed-file', required=True)
    parser.add_argument('--test-at', type=int, default=None, help=argparse.SUPPRESS)
    args = parser.parse_args()
    try:
        seed = read_seed(args.seed_file)
        when = int(time.time()) if args.test_at is None else int(args.test_at)
        sys.stdout.write(totp(seed, when) + '\n')
        return 0
    except TotpError as exc:
        sys.stderr.write(str(exc) + '\n')
        return 78

if __name__ == '__main__':
    raise SystemExit(main())
