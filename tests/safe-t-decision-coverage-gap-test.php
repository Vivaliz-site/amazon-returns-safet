<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';

function sdcgSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$engine = new SvAmazonSafeTDecisionEngine(null, new DateTimeImmutable('2026-09-08T22:15:00Z'));

// A seller-initiated refund is a valid, known initiator, but it is not proof that
// Amazon (the customer-facing party) actually refunded the customer. Without that
// proof, a new SAFE-T claim must wait, never be assumed confirmed.
$sellerInitiatedCase = [
    'id' => 951, 'amazon_order_id' => '701-1111111-1111111', 'safe_t_id' => null,
    'state' => 'REFUND_DETECTED', 'program' => 'MERCHANT_FULFILLED', 'physical_status' => 'NOT_RECEIVED',
    'refund_at' => '2026-09-01 12:00:00', 'refund_initiator' => 'SELLER',
    'expected_reimbursement_amount' => '100.00', 'reconciled_credit_amount' => '0.00',
];
$sellerInitiatedResult = $engine->nextAction($sellerInitiatedCase, [], ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE']);
sdcgSame('WAIT', $sellerInitiatedResult['action'] ?? null, 'A seller-initiated refund without Amazon customer-refund confirmation must wait, not start a new claim.');
sdcgSame('AMAZON_CUSTOMER_REFUND_NOT_CONFIRMED', $sellerInitiatedResult['reason'] ?? null, 'Unconfirmed Amazon customer refund must be named explicitly.');

// A resolved Seller Support case whose text matches none of the known resolution
// patterns (reimbursement, email-review redirect, SAFE-T appeal) must block for
// human review rather than be assumed as any of those outcomes.
$ambiguousSupportCase = [
    'id' => 952, 'amazon_order_id' => '701-2222222-2222222', 'safe_t_id' => '98143-99485-952',
    'state' => 'SUPPORT_ESCALATION', 'program' => 'DELIVERY_BY_AMAZON', 'physical_status' => 'NOT_RECEIVED',
    'refund_at' => '2026-09-01 12:00:00', 'refund_initiator' => 'AMAZON_CUSTOMER_SERVICE',
    'expected_reimbursement_amount' => '100.00', 'reconciled_credit_amount' => '0.00',
    'support_case_id' => '21839099001', 'latest_denial_text' => 'Recurso negado pela Amazon.',
];
$ambiguousSupportEvent = [
    'id' => 601, 'case_id' => 952, 'event_type' => 'SELLER_SUPPORT_STATUS_OBSERVED', 'source' => 'SELLER_CENTRAL',
    'occurred_at' => '2026-09-08 22:10:00', 'payload' => [
        'case_id' => '21839099001', 'case_status' => 'CLOSED',
        'latest_text' => 'Seu caso foi encerrado sem mais detalhes.',
    ],
];
$ambiguousSupportResult = $engine->nextAction($ambiguousSupportCase, [$ambiguousSupportEvent], ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE']);
sdcgSame('BLOCKED_REVIEW', $ambiguousSupportResult['action'] ?? null, 'A closed Seller Support case matching no known resolution pattern must block for human review.');
sdcgSame('SELLER_SUPPORT_RESOLUTION_AMBIGUOUS', $ambiguousSupportResult['reason'] ?? null, 'Ambiguous Seller Support resolution must be named explicitly.');

// A support-escalation case with no denial text on file (neither on the case nor
// in the event history) must block for human review rather than fingerprint an
// empty string and proceed as if a real denial reason existed.
$noDenialTextCase = [
    'id' => 953, 'amazon_order_id' => '701-3333333-3333333', 'safe_t_id' => '98143-99485-953',
    'state' => 'SUPPORT_ESCALATION', 'program' => 'DELIVERY_BY_AMAZON', 'physical_status' => 'NOT_RECEIVED',
    'refund_at' => '2026-09-01 12:00:00', 'refund_initiator' => 'AMAZON_CUSTOMER_SERVICE',
    'expected_reimbursement_amount' => '100.00', 'reconciled_credit_amount' => '0.00',
];
$noDenialTextResult = $engine->nextAction($noDenialTextCase, [], ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE']);
sdcgSame('BLOCKED_REVIEW', $noDenialTextResult['action'] ?? null, 'A support-escalation case with no denial text on file must block for human review.');
sdcgSame('DENIAL_TEXT_MISSING', $noDenialTextResult['reason'] ?? null, 'Missing denial text must be named explicitly.');

// A partial credit already on file must be verified against real finances before
// starting a brand-new SAFE-T claim — never assume it is stale and claim again.
$partialBeforeNewClaimCase = [
    'id' => 954, 'amazon_order_id' => '701-4444444-4444444', 'safe_t_id' => null,
    'state' => 'REFUND_DETECTED', 'program' => 'DELIVERY_BY_AMAZON', 'physical_status' => 'NOT_RECEIVED',
    'refund_initiator' => 'UNKNOWN',
    'expected_reimbursement_amount' => '100.00', 'reconciled_credit_amount' => '5.00',
];
$partialBeforeNewClaimResult = $engine->nextAction($partialBeforeNewClaimCase, [], ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE']);
sdcgSame('CHECK_FINANCES', $partialBeforeNewClaimResult['action'] ?? null, 'A partial credit on file must be verified before starting a brand-new SAFE-T claim.');
sdcgSame('PARTIAL_REIMBURSEMENT_VERIFY_BEFORE_NEW_CLAIM', $partialBeforeNewClaimResult['reason'] ?? null, 'Pre-claim partial-reimbursement verification must be named explicitly.');

// A partial credit already on file must also be verified before a denied claim is
// recovered via appeal — never assume the credit is stale and appeal blindly.
$partialBeforeAppealCase = [
    'id' => 955, 'amazon_order_id' => '701-5555555-5555555', 'safe_t_id' => '98143-99485-955',
    'state' => 'SAFE_T_DENIED', 'program' => 'DELIVERY_BY_AMAZON', 'physical_status' => 'NOT_RECEIVED',
    'refund_at' => '2026-09-01 12:00:00', 'refund_initiator' => 'AMAZON_CUSTOMER_SERVICE',
    'expected_reimbursement_amount' => '100.00', 'reconciled_credit_amount' => '5.00',
];
$partialBeforeAppealResult = $engine->nextAction($partialBeforeAppealCase, [], ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE']);
sdcgSame('CHECK_FINANCES', $partialBeforeAppealResult['action'] ?? null, 'A partial credit on file must be verified before recovering a denied claim via appeal.');
sdcgSame('PARTIAL_REIMBURSEMENT_VERIFY_BEFORE_RECOVERY_APPEAL', $partialBeforeAppealResult['reason'] ?? null, 'Pre-appeal partial-reimbursement verification must be named explicitly.');

echo "safe-t-decision-coverage-gap-test: OK\n";
