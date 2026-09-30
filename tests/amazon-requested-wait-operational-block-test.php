<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function arobSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}

$case=[
    'id'=>13228,
    'amazon_order_id'=>'701-8413776-8628228',
    'program'=>'FBA',
    'safe_t_id'=>'29787-26575-0026466',
    'state'=>'SAFE_T_DENIED',
    'physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-08-20 12:00:00',
    'seller_debit_at'=>'2026-08-20 12:00:00',
    'refund_initiator'=>'AMAZON_AUTOMATIC',
    'expected_reimbursement_amount'=>'100.00',
    'reconciled_credit_amount'=>'0.00',
    'appeal_deadline_at'=>'2026-10-04 03:00:00',
];
$blocked=[
    'id'=>798528,
    'case_id'=>13228,
    'event_type'=>'SELLER_CENTRAL_ACTION_RESULT',
    'source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-29 14:29:45',
    'payload'=>[
        'action'=>'SAFE_T_APPEAL',
        'status'=>'BLOCKED_UNTIL',
        'reason'=>'SAFE_T_APPEAL_FIELD_UNAVAILABLE',
        'block_reason'=>'Claim details. View All SAFE-T Claims. File a new SAFE-T Claim.',
        'next_allowed_at'=>null,
        'submitted'=>false,
        'retry_safe'=>true,
    ],
];
arobSame(
    null,
    SvAmazonRequestedWait::decision($case,[$blocked],new DateTimeImmutable('2026-09-30T10:00:00Z')),
    'A transient UI appeal-field block is not an Amazon instruction to wait and must not become AMAZON_WAIT_DATE_UNRESOLVED.'
);
$engine=new SvAmazonSafeTDecisionEngine();
$d=$engine->nextAction(
    $case,
    [$blocked],
    ['eligible'=>true,'policy_version_id'=>1,'eligibility_at'=>'2026-08-20 12:00:00'],
    new DateTimeImmutable('2026-09-30T10:00:00Z')
);
arobSame('SAFE_T_APPEAL',$d['action']??null,'The underlying appeal decision must remain active while the bridge retries its deferred UI block.');

$eligibilityBlock=$blocked;
$eligibilityBlock['payload']['reason']='SELLER_CENTRAL_NOT_ELIGIBLE';
$eligibilityBlock['payload']['block_reason']='NOT_ELIGIBLE';
arobSame(
    'HUMAN_REVIEW',
    SvAmazonRequestedWait::decision($case,[$eligibilityBlock],new DateTimeImmutable('2026-09-30T10:00:00Z'))['action']??null,
    'A real eligibility block with no date must remain fail-closed.'
);

echo "amazon-requested-wait-operational-block-test: OK\n";
