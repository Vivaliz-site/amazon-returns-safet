<?php
declare(strict_types=1);

require_once __DIR__.'/../workers/amazon-returns/reconcile.php';

function rofcSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}
function rofcAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

rofcSame(false,SvAmazonReturnStates::isTerminal(SvAmazonReturnStates::RECEIVED_OK),
    'Physical RECEIVED_OK must not be an operationally terminal state while financial recovery can remain open.');

$now=new DateTimeImmutable('2026-10-04 18:00:00',new DateTimeZone('UTC'));
$received=[
    'id'=>7001,
    'state'=>SvAmazonReturnStates::RECEIVED_OK,
    'physical_status'=>SvAmazonReturnPhysicalStatuses::RECEIVED_OK,
    'terminal_reason'=>'PHYSICAL_RETURN_RECEIVED',
    'closed_at'=>'2026-09-20 12:00:00',
    'next_action_at'=>'2026-10-05 12:00:00',
    'refund_amount'=>'100.00',
    'expected_reimbursement_amount'=>'100.00',
    'reconciled_credit_amount'=>'0.00',
    'quantity_ordered'=>1,
    'quantity_refunded'=>1,
];
$open=SvAmazonReturnsReconcileWorker::caseUpdate(
    $received,
    ['state'=>SvAmazonReturnStates::RECEIVED_OK,'credit_amount'=>'0.00'],
    [],
    $now
);
rofcSame(null,$open['closed_at'],'RECEIVED_OK with outstanding financial exposure must be reopened.');
rofcSame(null,$open['terminal_reason'],'RECEIVED_OK with outstanding financial exposure cannot retain a terminal reason.');
rofcSame('2026-10-05 12:00:00',$open['next_action_at'],'Reopened physical receipt must retain its scheduled recovery action.');

$noExposure=array_replace($received,[
    'refund_amount'=>'0.00',
    'expected_reimbursement_amount'=>'0.00',
    'closed_at'=>null,
    'terminal_reason'=>null,
]);
$physicalOnly=SvAmazonReturnsReconcileWorker::caseUpdate(
    $noExposure,
    ['state'=>SvAmazonReturnStates::RECEIVED_OK,'credit_amount'=>'0.00'],
    [],
    $now
);
rofcAssert(($physicalOnly['closed_at']??null)!==null,'RECEIVED_OK with no financial exposure may remain physically concluded.');

$projected=SvAmazonReturnProjector::projectFrom([
    'id'=>7002,
    'state'=>SvAmazonReturnStates::AWAITING_RETURN,
    'physical_status'=>SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
    'quantity_ordered'=>1,
    'refund_amount'=>'100.00',
    'expected_reimbursement_amount'=>'100.00',
    'reconciled_credit_amount'=>'0.00',
    'closed_at'=>null,
    'terminal_reason'=>null,
],[
    [
        'id'=>1,'case_id'=>7002,'event_type'=>'REFUND_CONFIRMED','source'=>'SP_API',
        'source_event_id'=>'refund-7002','occurred_at'=>'2026-09-19 10:00:00',
        'payload'=>['quantity_refunded'=>1,'refund_amount'=>'100.00','refund_at'=>'2026-09-19 10:00:00'],
    ],
    [
        'id'=>2,'case_id'=>7002,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE',
        'source_event_id'=>'warehouse-7002','occurred_at'=>'2026-09-20 12:00:00',
        'payload'=>['quantity_received_total'=>1,'condition'=>'OK','operator_id'=>99],
    ],
]);
rofcSame(SvAmazonReturnPhysicalStatuses::RECEIVED_OK,$projected['physical_status'],'Physical receipt must still be represented.');
rofcSame(SvAmazonReturnStates::RECEIVED_OK,$projected['state'],'Physical receipt state must remain visible.');
rofcSame(null,$projected['closed_at'],'Projector must not financially close a refunded case merely because the item was physically received.');
rofcSame(null,$projected['terminal_reason'],'Projector must not assign a terminal reason while financial exposure remains.');

$repoSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/CaseRepository.php');
rofcAssert(!str_contains($repoSource,"state IN ('RECOVERED','CLOSED_LOSS','RECEIVED_OK')"),
    'Operational concluded filters must not classify RECEIVED_OK as closed without closed_at.');
$coordinator=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/DecisionCoordinator.php');
rofcAssert(!str_contains($coordinator,'SvAmazonReturnStates::CLOSED_LOSS,SvAmazonReturnStates::RECEIVED_OK'),
    'Review cleanup must not resolve an open RECEIVED_OK case merely from its physical state.');

echo "received-ok-financial-closure-test: OK\n";
