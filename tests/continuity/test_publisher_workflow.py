from pathlib import Path
import unittest


class PublisherWorkflowContractTest(unittest.TestCase):
    def test_already_merged_branch_is_an_idempotent_success(self):
        workflow = Path('.github/workflows/continuity-publisher.yml').read_text(encoding='utf-8')
        self.assertIn('mergedAt', workflow)
        self.assertIn('ALREADY_MERGED=true', workflow)
        self.assertLess(workflow.index('mergedAt'), workflow.index('gh pr create'))
        self.assertEqual(3, workflow.count("if: env.ALREADY_MERGED != 'true'"))


if __name__ == '__main__':
    unittest.main()
