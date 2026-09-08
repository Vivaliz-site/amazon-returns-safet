<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';

function frlSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}

$now=new DateTimeImmutable('2026-09-08 04:00:00',new DateTimeZone('UTC'));
$case=[
    'id'=>16,'amazon_order_id'=>'702-7825391-8710636','program'=>'FBA',
    'safe_t_id'=>'45920-28095-3340347','state'=>'SAFE_T_DENIED',
    'appeal_deadline_at'=>'2026-09-14 12:00:00','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-07-16 23:12:54','seller_debit_at'=>'2026-07-16 23:12:54',
    'expected_reimbursement_amount'=>'68.48','reconciled_credit_amount'=>'50.25',
];
$policy=['eligible'=>false,'state'=>'CREDIT_PENDING','policy_version_id'=>7441,'eligibility_at'=>null];
$promise=[
    'id'=>1,'case_id'=>16,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-07 22:00:00','payload'=>[
        'safe_t_id'=>'45920-28095-3340347',
        'decision_text'=>'Voce sera reembolsado proativamente ate 7 de setembro de 2026.',
    ],
];
$checked=[
    'id'=>2,'case_id'=>16,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-08 03:50:00','payload'=>[
        'refresh_complete'=>true,'credit_amount'=>'50.25','outstanding_amount'=>'18.23',
        'unclassified_transactions'=>4,
        'ambiguous_reimbursement_transactions'=>0,
        'unsettled_financial_evidence'=>false,
    ],
];

$decision=SvAmazonReturnActionRouter::decide($case,[$promise,$checked],$policy,$now);
frlSame(null,$decision,'Fresh complete finance with only unrelated unclassified entries must leave CHECK_FINANCES and resume the existing SAFE-T lifecycle');

$uncertain=$checked;
$uncertain['payload']['ambiguous_reimbursement_transactions']=1;
$blocked=SvAmazonReturnActionRouter::decide($case,[$promise,$uncertain],$policy,$now);
frlSame('CHECK_FINANCES',$blocked['action']??null,'Potentially unclassified reimbursement evidence must remain blocked on finance');

$pending=$checked;
$pending['payload']['unsettled_financial_evidence']=true;
$blocked2=SvAmazonReturnActionRouter::decide($case,[$promise,$pending],$policy,$now);
frlSame('CHECK_FINANCES',$blocked2['action']??null,'Unsettled reimbursement evidence must remain blocked on finance');

echo "financial-recheck-loop-test: OK\n";
