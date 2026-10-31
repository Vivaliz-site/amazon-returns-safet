from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "continuity-signal.yml"

class WorkflowSecurityTests(unittest.TestCase):
    def test_github_context_is_not_interpolated_inside_shell(self) -> None:
        text = WORKFLOW.read_text(encoding="utf-8")
        run_block = text.split("run: |", 1)[1]
        self.assertNotIn("${{ github.ref_name }}", run_block)
        self.assertNotIn("${{ github.sha }}", run_block)
        self.assertIn("CONTINUITY_BRANCH: ${{ github.ref_name }}", text)
        self.assertIn("CONTINUITY_SHA: ${{ github.sha }}", text)
        self.assertIn('"$CONTINUITY_BRANCH"', run_block)
        self.assertIn('"$CONTINUITY_SHA"', run_block)

if __name__ == "__main__":
    unittest.main()
