<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SafeTDecisionEngine.php';

$engine = new SvAmazonSafeTDecisionEngine(null, new DateTimeImmutable('2026-09-08T22:15:00Z'));
$case = [
    'id' => 919,
    'amazon_order_id' => '701-0630116-9129834',
    'safe_t_id' => '98143-99485-9285859',
    'state' => 'EMAIL_REVIEW_RESPONSE_PENDING',
    'program' => 'DELIVERY_BY_AMAZON',
    'refund_at' => '2026-09-01 12:00:00',
    'refund_initiator' => 'AMAZON_CUSTOMER_SERVICE',
    'expected_reimbursement_amount' => '100.00',
    'reconciled_credit_amount' => '0.00',
    'physical_status' => 'NOT_RECEIVED',
    'customer_delivery_confirmed' => true,
    'customer_tracking_ids' => ['TBR420573721'],
    'customer_delivery_carriers' => ['Amazon'],
];
$timeline = [[
    'id' => 501,
    'case_id' => 919,
    'event_type' => 'SAFE_T_EMAIL_REVIEW_RESPONSE',
    'source' => 'GMAIL',
    'occurred_at' => '2026-09-08 22:10:00',
    'payload' => [
        'review_outcome' => 'UNKNOWN_AMBIGUOUS',
        'review_suggested_action' => 'HUMAN_REVIEW',
        'review_excerpt' => 'O cliente alega que não recebeu o pedido.',
        'gmail_thread_id' => 'thread-919',
        'gmail_rfc_message_id' => '<message-919@example.test>',
        'content_sha256' => hash('sha256', 'cliente-nao-recebeu'),
    ],
]];

$decision = $engine->nextAction($case, $timeline, ['eligible' => true, 'state' => 'SAFE_T_ELIGIBLE']);
if (($decision['action'] ?? null) !== 'SAFE_T_EMAIL_REPLY') {
    throw new RuntimeException('Trusted customer-delivery proof must resolve a customer nonreceipt contradiction automatically; got ' . json_encode($decision));
}
if (($decision['reason'] ?? null) !== 'CUSTOMER_NONRECEIPT_CONTRADICTED_BY_DELIVERY_EVIDENCE') {
    throw new RuntimeException('Automatic contradiction response must expose the dedicated reason; got ' . json_encode($decision));
}

echo "customer-nonreceipt-delivery-contradiction-test: OK\n";
