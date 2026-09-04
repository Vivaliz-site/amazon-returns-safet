<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTStatus.php';
require_once __DIR__ . '/../includes/amazon-returns/SafeTStatusService.php';

function stSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}

$normalized = SvAmazonSafeTStatus::normalize([
    'claim_status'=>'DENIED',
    'safe_t_id'=>'12472-25597-6629839',
    'order_id'=>'702-5349464-0245862',
    'appeal_deadline_at'=>'2026-09-08T14:29:00-03:00',
    'decision_text'=>'Após a análise do recurso, negamos sua solicitação de reembolso.',
    'appeal_submitted'=>true,
    'appeal_denied'=>true,
]);
stSame(true, $normalized['appeal_submitted'] ?? null, 'Normalized SAFE-T status must preserve appeal_submitted.');
stSame(true, $normalized['appeal_denied'] ?? null, 'Normalized SAFE-T status must preserve appeal_denied.');
stSame('APPEAL_DENIED_FINAL', SvAmazonSafeTStatusService::nextState('APPEAL_SUBMITTED', 'DENIED', true), 'Denied appeal must project APPEAL_DENIED_FINAL.');
stSame('SAFE_T_DENIED', SvAmazonSafeTStatusService::nextState('SAFE_T_SUBMITTED', 'DENIED', false), 'First denial must project SAFE_T_DENIED.');
stSame('APPEAL_APPROVED', SvAmazonSafeTStatusService::nextState('APPEAL_SUBMITTED', 'APPROVED', false), 'Approval after appeal must project APPEAL_APPROVED.');
stSame('SAFE_T_APPROVED', SvAmazonSafeTStatusService::nextState('SAFE_T_SUBMITTED', 'APPROVED', false), 'Initial claim approval must project SAFE_T_APPROVED.');

echo "amazon-returns-safe-t-status-test: OK\n";
