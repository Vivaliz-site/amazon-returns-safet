from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]


class SafeContinuitySignalTests(unittest.TestCase):
    def test_signal_interpolates_github_context_only_through_env(self):
        workflow = (ROOT / ".github/workflows/continuity-signal.yml").read_text(encoding="utf-8")
        self.assertEqual(workflow.count("run: |"), 1)
        run_block = workflow.split("run: |", 1)[1]
        self.assertNotIn("${{ github.ref_name }}", run_block)
        self.assertNotIn("${{ github.sha }}", run_block)
        self.assertIn("CONTINUITY_BRANCH: ${{ github.ref_name }}", workflow)
        self.assertIn("CONTINUITY_SHA: ${{ github.sha }}", workflow)
        self.assertIn('"$CONTINUITY_BRANCH"', run_block)
        self.assertIn('"$CONTINUITY_SHA"', run_block)
        self.assertIn("set -Eeuo pipefail", run_block)

    def test_global_manifest_does_not_reinstate_retired_solange_repo(self):
        manifest = json.loads((ROOT / "docs/quality/GLOBAL_AUDIT_MANIFEST.json").read_text(encoding="utf-8"))
        repositories = manifest.get("required_repositories", [])
        # Transfers preserve numeric repository identities, not stale owner paths.
        self.assertNotIn("fredmourao-ai/solange-rolla", repositories)
        self.assertNotIn("fredmourao-ai/solange-rolla-consultorio", repositories)
        self.assertNotIn("fredmourao-ai/mei-mg-email", repositories)
        self.assertEqual(repositories.count("Vivaliz-site/solange-rolla-consultorio"), 1)
        self.assertEqual(repositories.count("Vivaliz-site/mei-mg-email"), 1)


if __name__ == "__main__":
    unittest.main()
