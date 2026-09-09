<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Projector.php';

function refundReplaySame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message."\nExpected: ".var_export($expected,true)."\nActual: ".var_export($actual,true));
    }
}

$case=[
    'id'=>1,
    'amazon_order_id'=>'701-0630116-9129834',
    'quantity_ordered'=>2,
    'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
    'terminal_reason'=>null,
    'closed_at'=>null,
];

// Historical REFUND_ISSUED_EMAIL rows persisted before refund_amount was added to
// the event payload. Production still contains this exact shape: amount is present,
// refund_amount is absent. Replay must remain backward-compatible.
$projected=SvAmazonReturnProjector::projectFrom($case, [[
    'case_id'=>1,
    'event_type'=>'REFUND_ISSUED_EMAIL',
    'source'=>'GMAIL',
    'occurred_at'=>'2026-09-02 01:50:37',
    'payload'=>[
        'order_id'=>'701-0630116-9129834',
        'amount'=>'85.50',
        'currency'=>'BRL',
        'financial_truth'=>false,
    ],
]]);

refundReplaySame(
    '85.50',
    $projected['refund_amount'] ?? null,
    'Legacy Gmail refund amount must survive projector replay.'
);
refundReplaySame(
    '2026-09-02 01:50:37',
    $projected['refund_at'] ?? null,
    'Legacy Gmail refund timestamp must remain replayable.'
);

echo "refund-replay-baseline-regression-test: OK\n";
