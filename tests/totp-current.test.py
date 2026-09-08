import base64
import os
import pathlib
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'scripts' / 'amazon-returns' / 'totp-current.py'
RFC_SECRET = b'12345678901234567890'
RFC_BASE32 = base64.b32encode(RFC_SECRET).decode('ascii')


class TotpCurrentTests(unittest.TestCase):
    def run_script(self, seed_text=RFC_BASE32, mode=0o600, *extra):
        with tempfile.TemporaryDirectory() as tmp:
            seed = pathlib.Path(tmp) / 'seed'
            seed.write_text(seed_text, encoding='ascii')
            os.chmod(seed, mode)
            return subprocess.run(
                ['python3', str(SCRIPT), '--seed-file', str(seed), *extra],
                text=True, capture_output=True, check=False,
            )

    def test_rfc6238_sha1_vector_is_six_digit_truncation(self):
        result = self.run_script(RFC_BASE32, 0o600, '--test-only', '--test-time', '59')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout, '287082\n')
        self.assertEqual(result.stderr, '')

    def test_test_time_requires_explicit_test_only_switch(self):
        result = self.run_script(RFC_BASE32, 0o600, '--test-time', '59')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')
        self.assertIn('TEST_TIME_REQUIRES_TEST_ONLY', result.stderr)

    def test_rejects_invalid_base32_without_echoing_seed(self):
        bad = 'NOT-A-BASE32-SEED!'
        result = self.run_script(bad, 0o600)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')
        self.assertIn('SEED_INVALID', result.stderr)
        self.assertNotIn(bad, result.stderr)

    def test_rejects_group_or_world_access(self):
        for mode in (0o640, 0o644):
            with self.subTest(mode=oct(mode)):
                result = self.run_script(RFC_BASE32, mode)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(result.stdout, '')
                self.assertIn('SEED_PERMISSIONS_INVALID', result.stderr)

    def test_missing_seed_fails_closed(self):
        result = subprocess.run(
            ['python3', str(SCRIPT), '--seed-file', '/definitely/missing/amazon-totp-seed'],
            text=True, capture_output=True, check=False,
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, '')
        self.assertIn('SEED_NOT_CONFIGURED', result.stderr)


if __name__ == '__main__':
    unittest.main()