<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/FinancialReconciler.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialCheckEvidence.php';

function fraSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}

$reconciler=new SvAmazonFinancialReconciler();
$case=['id'=>77,'marketplace_id'=>'A2Q3Y263D00KWC','expected_reimbursement_amount'=>'100.00','state'=>'CREDIT_PENDING'];
$normal=[
    'transaction_id'=>'normal-refund','transaction_type'=>'REFUND','transaction_status'=>'RELEASED',
    'total_amount'=>['amount'=>'100.00','currency'=>'BRL'],
];
$r=$reconciler->reconcile($case,[$normal]);
fraSame(1,$r['unclassified_transactions']??null,'Normal non-reimbursement ledger entries may remain unclassified');
fraSame(0,$r['ambiguous_reimbursement_transactions']??null,'Normal non-reimbursement ledger entries must not create reimbursement uncertainty');
fraSame(false,$r['unsettled_financial_evidence']??null,'Normal ledger entries are not unsettled reimbursement evidence');

$suspicious=[
    'transaction_id'=>'currency-mismatch','transaction_type'=>'SAFE_T_REIMBURSEMENT','transaction_status'=>'RELEASED',
    'total_amount'=>['amount'=>'18.23','currency'=>'USD'],
];
$r2=$reconciler->reconcile($case,[$normal,$suspicious]);
fraSame(1,$r2['ambiguous_reimbursement_transactions']??null,'A reimbursement-like transaction that cannot be safely reconciled must block automation');

$refresh=[[
    'id'=>5,'case_id'=>77,'event_type'=>'FINANCIAL_REFRESH_CONFIRMED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-08 03:55:00','payload'=>['refresh_complete'=>true],
]];
$receipt=SvAmazonFinancialCheckEvidence::reconciled(77,$refresh,$r,new DateTimeImmutable('2026-09-08 04:00:00',new DateTimeZone('UTC')));
fraSame(0,$receipt['payload']['ambiguous_reimbursement_transactions']??null,'Financial receipt must persist reimbursement-specific uncertainty');
fraSame(false,$receipt['payload']['unsettled_financial_evidence']??null,'Financial receipt must persist unsettled reimbursement evidence');

echo "financial-ambiguity-test: OK\n";
