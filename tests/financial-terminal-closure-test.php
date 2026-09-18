<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/reconcile.php';

function ftcSame(mixed $want,mixed $got,string $why): void {
    if ($want !== $got) throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}
if (!method_exists(SvAmazonReturnsReconcileWorker::class,'caseUpdate')) {
    throw new RuntimeException('Financial worker must expose deterministic terminal-closure updates.');
}
$now=new DateTimeImmutable('2026-09-18 03:30:00',new DateTimeZone('UTC'));
$receipt=[
    'id'=>77,'state'=>SvAmazonReturnStates::RECEIVED_OK,'terminal_reason'=>null,'closed_at'=>null,
    'reconciled_credit_amount'=>'0.00','next_action_at'=>'2026-09-20 10:00:00',
    'quantity_ordered'=>1,'quantity_refunded'=>1,'physical_status'=>'RECEIVED_OK',
];
$receiptEvents=[[
    'id'=>9,'case_id'=>77,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE',
    'source_event_id'=>'warehouse-77','occurred_at'=>'2026-07-10 12:00:00',
    'payload'=>['quantity'=>1,'condition'=>'OK','operator_id'=>1234],
]];
$receiptUpdate=SvAmazonReturnsReconcileWorker::caseUpdate(
    $receipt,['state'=>SvAmazonReturnStates::RECEIVED_OK,'credit_amount'=>'0.00'],$receiptEvents,$now
);
ftcSame('2026-07-10 12:00:00',$receiptUpdate['closed_at'],'RECEIVED_OK must recover its original physical closure timestamp.');
ftcSame('PHYSICAL_RETURN_RECEIVED',$receiptUpdate['terminal_reason'],'RECEIVED_OK must recover its physical terminal reason.');
ftcSame(null,$receiptUpdate['next_action_at'],'Terminal physical receipt must not keep a future action.');

$loss=[
    'id'=>88,'state'=>SvAmazonReturnStates::CLOSED_LOSS,'terminal_reason'=>null,'closed_at'=>null,
    'reconciled_credit_amount'=>'0.00','next_action_at'=>'2026-09-20 11:00:00',
];
$lossUpdate=SvAmazonReturnsReconcileWorker::caseUpdate(
    $loss,['state'=>SvAmazonReturnStates::CLOSED_LOSS,'credit_amount'=>'0.00'],[],$now
);
ftcSame('2026-09-18 03:30:00',$lossUpdate['closed_at'],'Corrupted CLOSED_LOSS must be re-closed deterministically.');
ftcSame('EMAIL_REVIEW_FINAL_DENIAL',$lossUpdate['terminal_reason'],'Corrupted CLOSED_LOSS must restore its canonical terminal reason.');
ftcSame(null,$lossUpdate['next_action_at'],'CLOSED_LOSS must not keep a future action.');

$existingLoss=[
    'id'=>89,'state'=>SvAmazonReturnStates::CLOSED_LOSS,
    'terminal_reason'=>'EMAIL_REVIEW_FINAL_DENIAL','closed_at'=>'2026-08-30 09:15:00',
    'reconciled_credit_amount'=>'0.00','next_action_at'=>null,
];
$existingLossUpdate=SvAmazonReturnsReconcileWorker::caseUpdate(
    $existingLoss,['state'=>SvAmazonReturnStates::CLOSED_LOSS,'credit_amount'=>'0.00'],[],$now
);
ftcSame('2026-08-30 09:15:00',$existingLossUpdate['closed_at'],'Existing loss closure timestamp must remain immutable.');
ftcSame('EMAIL_REVIEW_FINAL_DENIAL',$existingLossUpdate['terminal_reason'],'Existing loss reason must remain immutable.');

$pending=['id'=>99,'state'=>SvAmazonReturnStates::CREDIT_PENDING,'closed_at'=>null,'terminal_reason'=>null,'next_action_at'=>'2026-09-20 12:00:00'];
$recovered=SvAmazonReturnsReconcileWorker::caseUpdate(
    $pending,['state'=>SvAmazonReturnStates::RECOVERED,'credit_amount'=>'55.00'],[],$now
);
ftcSame('2026-09-18 03:30:00',$recovered['closed_at'],'Financial recovery must close the case.');
ftcSame('FINANCIAL_RECOVERED',$recovered['terminal_reason'],'Financial recovery must have financial terminal reason.');
ftcSame(null,$recovered['next_action_at'],'Recovered case must clear next action.');

$wasRecovered=[
    'id'=>100,'state'=>SvAmazonReturnStates::RECOVERED,'closed_at'=>'2026-09-01 08:00:00',
    'terminal_reason'=>'FINANCIAL_RECOVERED','next_action_at'=>null,
];
$reopened=SvAmazonReturnsReconcileWorker::caseUpdate(
    $wasRecovered,['state'=>SvAmazonReturnStates::CREDIT_PENDING,'credit_amount'=>'25.00'],[],$now
);
ftcSame(null,$reopened['closed_at'],'A real financial shortfall must reopen a previously recovered case.');
ftcSame(null,$reopened['terminal_reason'],'A reopened case must clear its terminal reason.');

$receiptRecovered=SvAmazonReturnsReconcileWorker::caseUpdate(
    $receipt,['state'=>SvAmazonReturnStates::RECOVERED,'credit_amount'=>'55.00'],$receiptEvents,$now
);
ftcSame(SvAmazonReturnStates::RECOVERED,$receiptRecovered['state'],'Actual reconciled credit must take precedence over physical terminal state.');
ftcSame('FINANCIAL_RECOVERED',$receiptRecovered['terminal_reason'],'Actual credit must use the financial recovery reason.');

echo "financial-terminal-closure-test: OK\n";
