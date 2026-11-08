from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]

class AuditGovernanceAuthWorkflowTest(unittest.TestCase):
    def test_global_policy_fetch_uses_authenticated_read_only_workflow_token(self):
        workflow=(ROOT/".github/workflows/audit-governance.yml").read_text()
        self.assertIn("permissions:",workflow)
        self.assertIn("contents: read",workflow)
        self.assertIn("GITHUB_TOKEN: "+chr(36)+"{{ github.token }}",workflow)
        self.assertIn("python scripts/validate-global-audit-policy-authenticated.py",workflow)
        self.assertNotIn("python scripts/validate-global-audit-policy.py --output-dir",workflow)

if __name__=="__main__":
    unittest.main()
