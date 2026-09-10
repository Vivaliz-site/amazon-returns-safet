<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Projector.php';

$case = [
    'id'=>921,
    'amazon_order_id'=>'701-0630116-9129834',
    'quantity_ordered'=>2,
    'physical_status'=>'NOT_RECEIVED',
    'state'=>'POLICY_REVIEW_REQUIRED',
];
$historicalGmailEvent = [[
    'case_id'=>921,
    'event_type'=>'REFUND_ISSUED_EMAIL',
    'source'=>'GMAIL',
    'occurred_at'=>'2026-09-02 01:50:37',
    'payload'=>[
        'order_id'=>'701-0630116-9129834',
        'program'=>null,
        'refund_initiator'=>null,
        'refund_at'=>'2026-09-02 01:50:37',
        'refund_amount'=>'85.50',
        'financial_truth'=>false,
    ],
]];

$projected = SvAmazonReturnProjector::projectFrom($case, $historicalGmailEvent);
if (($projected['program'] ?? null) !== 'UNKNOWN') {
    throw new RuntimeException('Historical null program must mean no observed program evidence.');
}
if (($projected['refund_initiator'] ?? null) !== 'UNKNOWN') {
    throw new RuntimeException('Historical null refund initiator must mean no observed initiator evidence.');
}
if (($projected['refund_amount'] ?? null) !== '85.50') {
    throw new RuntimeException('Ignoring absent enums must preserve the refund evidence.');
}

echo "projector-null-optional-enum-regression-test: OK\n";
