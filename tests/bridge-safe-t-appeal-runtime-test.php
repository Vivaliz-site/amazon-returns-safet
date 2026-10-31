<?php
declare(strict_types=1);

// Regression for 2026-10-16 production SAFE_T_APPEAL bridge HTTP 500.
require_once __DIR__.'/../includes/amazon-returns/BridgeService.php';

if (!class_exists('SvAmazonSafeTStatusService', false)) {
    throw new RuntimeException('BridgeService must load SAFE-T status reconciliation before processing appeal results.');
}

$method = new ReflectionMethod(SvAmazonReturnsBridgeService::class, 'completeSuccess');
$lines = file($method->getFileName());
$body = implode('', array_slice($lines, $method->getStartLine()-1, $method->getEndLine()-$method->getStartLine()+1));
if (!str_contains($body, '$case=$this->p->cases->find($caseId);')) {
    throw new RuntimeException('Appeal completion must load the persisted case before reconciliation.');
}

$actual = SvAmazonSafeTStatusService::reconcileAfterAppealSubmission(
    ['safe_t_id'=>'SAFE-T-TEST','state'=>'SAFE_T_DENIED'],
    [],
    new DateTimeImmutable('2026-10-16T10:00:00Z')
);
if ($actual !== []) {
    throw new RuntimeException('Expected no state patch without historical denial evidence.');
}
echo "Bridge SAFE-T appeal runtime dependency PASS\n";
