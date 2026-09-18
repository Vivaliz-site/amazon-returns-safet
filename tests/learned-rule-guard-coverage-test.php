<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';

function lrgcSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$engine = new SvAmazonSafeTDecisionEngine();
$now = new DateTimeImmutable('2026-09-06T03:00:00Z');
$case = [
    'id' => 71, 'amazon_order_id' => '702-y', 'safe_t_id' => null, 'state' => 'REFUND_DETECTED',
    'physical_status' => 'NOT_RECEIVED', 'refund_at' => '2026-07-20 00:00:00',
    'refund_initiator' => 'AMAZON_AUTOMATIC', 'expected_reimbursement_amount' => '100', 'reconciled_credit_amount' => '0',
];

// A learned rule instructing a SAFE-T appeal with no SAFE-T claim to appeal against
// must never be executed blindly: it must require human review.
$appealResult = $engine->guardLearnedEffect(
    ['action' => 'SAFE_T_APPEAL', 'parameters' => ['date_binding' => 'NONE']],
    $case, [], ['eligible' => true], $now
);
lrgcSame('HUMAN_REVIEW', $appealResult['action'] ?? null, 'A learned SAFE-T appeal without a claim ID must require human review.');
lrgcSame('LEARNED_RULE_APPEAL_GATE_BLOCKED', $appealResult['reason'] ?? null, 'Missing claim/deadline for a learned appeal must be named explicitly.');

// A learned rule instructing a Seller Support write with no SAFE-T claim to reference
// must never be executed blindly: it must require human review.
$supportResult = $engine->guardLearnedEffect(
    ['action' => 'SELLER_SUPPORT_OPEN', 'parameters' => ['date_binding' => 'NONE']],
    $case, [], ['eligible' => true], $now
);
lrgcSame('HUMAN_REVIEW', $supportResult['action'] ?? null, 'A learned Seller Support write without a SAFE-T claim must require human review.');
lrgcSame('LEARNED_RULE_SAFE_T_REQUIRED', $supportResult['reason'] ?? null, 'Missing SAFE-T claim for a learned support write must be named explicitly.');

// A learned rule instructing a WAIT with no resolved date bound to it must never be
// executed blindly: an unbounded wait can never wake back up, so it requires human review.
$waitResult = $engine->guardLearnedEffect(
    ['action' => 'WAIT', 'parameters' => ['date_binding' => 'NONE']],
    $case, [], ['eligible' => true], $now
);
lrgcSame('HUMAN_REVIEW', $waitResult['action'] ?? null, 'A learned WAIT without a resolved date must require human review, never wait forever.');
lrgcSame('LEARNED_RULE_WAIT_DATE_UNRESOLVED', $waitResult['reason'] ?? null, 'Unresolved wait date for a learned rule must be named explicitly.');

echo "learned-rule-guard-coverage-test: OK\n";
