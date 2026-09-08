import os
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts/amazon-returns/totp-current.py'
RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'

class TotpCurrentTest(unittest.TestCase):
    def seed_file(self, value=RFC_SECRET, mode=0o600):
        tmp = tempfile.NamedTemporaryFile('w', delete=False)
        tmp.write(value + '\n')
        tmp.close()
        os.chmod(tmp.name, mode)
        self.addCleanup(lambda: Path(tmp.name).unlink(missing_ok=True))
        return tmp.name

    def run_totp(self, *args):
        return subprocess.run(
            ['python3', str(SCRIPT), *args],
            text=True, capture_output=True, check=False,
        )

    def test_rfc6238_sha1_vector_truncated_to_six_digits(self):
        seed = self.seed_file()
        result = self.run_totp('--seed-file', seed, '--test-at', '59')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, '287082\n')
        self.assertEqual(result.stderr, '')

    def test_invalid_base32_fails_without_echoing_seed(self):
        seed = self.seed_file('INVALID-SEED-!')
        result = self.run_totp('--seed-file', seed, '--test-at', '59')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('INVALID-SEED', result.stdout + result.stderr)
        self.assertNotRegex(result.stdout, r'^\d{6}\s*$')

    def test_missing_seed_file_fails_closed(self):
        result = self.run_totp('--seed-file', '/tmp/definitely-missing-shopvivaliz-seed')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')

    def test_over_permissive_seed_file_is_rejected(self):
        seed = self.seed_file(mode=0o644)
        self.assertEqual(stat.S_IMODE(os.stat(seed).st_mode), 0o644)
        result = self.run_totp('--seed-file', seed, '--test-at', '59')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('permissions', result.stderr.lower())

    def test_unknown_time_override_is_rejected_and_test_time_is_explicit(self):
        seed = self.seed_file()
        result = self.run_totp('--seed-file', seed, '--at', '59')
        self.assertNotEqual(result.returncode, 0)
        result = self.run_totp('--seed-file', seed, '--test-at', '1111111109')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertRegex(result.stdout, r'^081804\n$')

if __name__ == '__main__':
    unittest.main()
