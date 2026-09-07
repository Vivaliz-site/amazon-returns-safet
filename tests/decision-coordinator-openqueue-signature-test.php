<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';
final class SigReviews {
    public function openQueue(array $filters=[]): array { return []; }
    public function resolveOpenForCase(int $caseId): int { return 0; }
}
final class SigCases { public function find(int $caseId): ?array { return null; } }
final class SigRules { public function active(): array { return []; } }
final class SigApps { public function record(array $row): int { return 1; } }
final class SigEvents { public function append(array $row): int { return 1; } }
$p=(object)['reviews'=>new SigReviews(),'cases'=>new SigCases(),'learnedRules'=>new SigRules(),'ruleApplications'=>new SigApps(),'events'=>new SigEvents()];
new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$p);
echo "decision-coordinator-openqueue-signature-test: OK\n";
