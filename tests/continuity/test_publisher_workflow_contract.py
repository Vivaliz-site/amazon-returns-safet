from pathlib import Path
import unittest


class PublisherWorkflowContractTest(unittest.TestCase):
    def test_pr_creation_does_not_require_local_checkout(self):
        workflow = Path(".github/workflows/continuity-publisher.yml").read_text(encoding="utf-8")
        self.assertNotIn("gh pr create --repo \"$REPOSITORY\" --base main --head \"$BRANCH\" --fill", workflow)
        self.assertIn("--title", workflow)
        self.assertIn("--body", workflow)
        self.assertNotIn("actions/checkout", workflow)


if __name__ == "__main__":
    unittest.main()
