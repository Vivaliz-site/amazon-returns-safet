from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class AgentPolicyTests(unittest.TestCase):
    def test_all_agent_entrypoints_point_to_current_policy(self):
        for path in ("AGENTS.md", "CLAUDE.md"):
            with self.subTest(path=path):
                content = (ROOT / path).read_text(encoding="utf-8")
                self.assertIn("GEPETO_EVERY_TASK_V2", content)
                self.assertIn("GEPETO-POLICY.md", content)
        policy = (ROOT / "GEPETO-POLICY.md").read_text(encoding="utf-8")
        self.assertIn("GEPETO_UNAVAILABLE", policy)
        self.assertIn("SUPERPOWERS_UNAVAILABLE", policy)
        self.assertNotIn("gpt-4d1b51dc23427fe5106bbd896a5b7a0e", policy)
        self.assertIn("origem excluida shopvivaliz-free-a1", policy)

    def test_governance_has_manual_dispatch_without_dropping_pr_guard(self):
        workflow = (ROOT / ".github/workflows/repository-governance.yml").read_text(encoding="utf-8")
        self.assertIn("  workflow_dispatch:", workflow)
        self.assertIn("  pull_request:", workflow)
        self.assertIn("  push:", workflow)
        self.assertIn("scripts/repository-governance-validate.sh ci", workflow)

if __name__ == "__main__":
    unittest.main()
