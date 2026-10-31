from __future__ import annotations

import importlib.util
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location(
    "amazon_ci_impact", ROOT / "scripts/ci-impact-selection.py"
)
assert spec and spec.loader
selection = importlib.util.module_from_spec(spec)
spec.loader.exec_module(selection)


class ImpactSelectionTests(unittest.TestCase):
    def test_docs_only_skips_unrelated_runtimes(self):
        result = selection.classify(["README.md", "docs/superpowers/notes.md"])
        self.assertEqual(result["mode"], "scoped")
        self.assertEqual(result["groups"], ["docs"])
        for name in ("php", "node", "continuity", "totp"):
            self.assertFalse(result[name])

    def test_isolated_totp_runs_only_totp(self):
        result = selection.classify(["scripts/amazon-returns/totp-current.py"])
        self.assertEqual(result["groups"], ["totp"])
        self.assertTrue(result["totp"])
        self.assertFalse(result["php"])

    def test_continuity_runs_its_own_tests(self):
        result = selection.classify(["tools/continuity/dispatcher.py", "tests/continuity/test_foo.py"])
        self.assertEqual(result["groups"], ["continuity"])
        self.assertTrue(result["continuity"])
        self.assertFalse(result["node"])

    def test_union_for_independent_changes(self):
        result = selection.classify(["tests/a.test.mjs", "tests/totp-current.test.py"])
        self.assertEqual(result["groups"], ["node", "totp"])

    def test_shared_code_and_coupled_surface_are_full(self):
        for path in (
            "includes/amazon-returns.php", "api/returns.php", "admin/index.php",
            "scripts/amazon-returns/seller-central-bridge-worker.mjs",
            "composer.lock", ".github/workflows/ci.yml",
            "docs/quality/AUDIT_ABSOLUTE_GATE_V1.md",
            "docs/runbooks/shopvivaliz-d45-operational-policy.md",
            "AGENTS.md", "docs/MEMORIA-DO-PROJETO.md",
        ):
            with self.subTest(path=path):
                result = selection.classify([path])
                self.assertEqual(result["mode"], "full")
                self.assertTrue(result["php"])

    def test_unknown_in_mixed_diff_forces_full(self):
        result = selection.classify(["docs/superpowers/notes.md", "src/shared/new-module.ts"])
        self.assertEqual(result["mode"], "full")
        self.assertIn("src/shared/new-module.ts", result["full_reasons"])

    def test_missing_or_untrusted_diff_and_push_force_full(self):
        self.assertEqual(selection.classify(None)["mode"], "full")
        self.assertEqual(selection.classify([])["mode"], "full")
        self.assertEqual(selection.classify(["README.md"], force_full=True)["mode"], "full")
        self.assertIsNone(selection.read_diff("badsha", "badsha"))

    def test_path_traversal_is_not_scoped(self):
        self.assertEqual(selection.classify(["../docs/notes.md"])["mode"], "full")
        self.assertEqual(selection.classify(["docs/../README.md"])["mode"], "full")

    def test_duplicate_paths_are_ignored(self):
        self.assertEqual(selection.classify(["README.md", "README.md"])["files"], ["README.md"])


if __name__ == "__main__":
    unittest.main()
