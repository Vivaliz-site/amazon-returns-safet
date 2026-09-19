<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function cfsAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function cfsSame(mixed $want,mixed $got,string $message):void{if($want!==$got)throw new RuntimeException($message.' expected='.json_encode($want).' actual='.json_encode($got));}

$engine=new SvAmazonSafeTDecisionEngine();
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$case=[
    'id'=>496,'amazon_order_id'=>'702-2751217-8386605','program'=>'FBA','safe_t_id'=>null,
    'state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-06-15 21:06:57','seller_debit_at'=>'2026-06-15 21:06:57',
    'refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'106.14','reconciled_credit_amount'=>'0.00',
];
$finance1=[
    'id'=>1001,'case_id'=>496,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-09 10:00:00','payload'=>[
        'refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'106.14',
        'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false,
    ],
];
$finance2=$finance1;
$finance2['id']=1002;
$finance2['occurred_at']='2026-09-09 10:30:00';

$first=$engine->nextAction($case,[$finance1],$policy,new DateTimeImmutable('2026-09-09 10:10:00',new DateTimeZone('UTC')));
$second=$engine->nextAction($case,[$finance1,$finance2],$policy,new DateTimeImmutable('2026-09-09 10:40:00',new DateTimeZone('UTC')));
cfsSame('SELLER_SUPPORT_OPEN',$first['action']??null,'First verified unpaid FBA balance must open Seller Support.');
cfsSame('SELLER_SUPPORT_OPEN',$second['action']??null,'A repeated fresh finance check still represents the same support-open intent.');
cfsSame('FBA_RETURNS_REIMBURSEMENT',$first['support_route']??null,'Classic FBA recovery must explicitly select the FBA reimbursement route.');
cfsSame('FBA_RETURNS_REIMBURSEMENT',$second['support_route']??null,'Repeated FBA recovery must keep the same explicit support route.');
cfsAssert(($first['idempotency_key']??'')!=='','Support-open decision must carry an idempotency key.');
cfsSame($first['idempotency_key'],$second['idempotency_key'],'Repeated finance receipts with unchanged unpaid balance must not create a new Seller Support opening.');

$closed=$case;
$closed['support_case_id']='CASE-OLD-123';
$closed['support_case_status']='CLOSED';
$nextEpisode=$engine->nextAction($closed,[$finance2],$policy,new DateTimeImmutable('2026-09-09 10:40:00',new DateTimeZone('UTC')));
cfsSame('SELLER_SUPPORT_OPEN',$nextEpisode['action']??null,'A closed prior support case may start a new recovery episode.');
cfsSame('FBA_RETURNS_REIMBURSEMENT',$nextEpisode['support_route']??null,'A later FBA recovery episode must keep the FBA reimbursement route.');
cfsAssert(($nextEpisode['idempotency_key']??'')!==($first['idempotency_key']??''),'A new support episode after a closed case must receive a new idempotency key.');

$mismatch=[
    'id'=>2001,'case_id'=>496,'event_type'=>'SELLER_SUPPORT_IDENTITY_MISMATCH','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-09 10:35:00','payload'=>[
        'support_case_id'=>'22144700811','order_id'=>'702-2751217-8386605',
        'reason'=>'SELLER_SUPPORT_CASE_IDENTITY_MISMATCH','binding_cleared'=>true,
    ],
];
$afterMismatch=$engine->nextAction($case,[$finance2,$mismatch],$policy,new DateTimeImmutable('2026-09-09 10:40:00',new DateTimeZone('UTC')));
cfsSame('SELLER_SUPPORT_OPEN',$afterMismatch['action']??null,'A proven cleared Seller Support identity collision must allow a replacement support opening.');
cfsAssert(($afterMismatch['idempotency_key']??'')!==($first['idempotency_key']??''),'Identity mismatch recovery must start a new deterministic support episode instead of reusing a previously succeeded poisoned key.');
$afterMismatchRepeat=$engine->nextAction($case,[$finance1,$finance2,$mismatch],$policy,new DateTimeImmutable('2026-09-09 10:45:00',new DateTimeZone('UTC')));
cfsSame($afterMismatch['idempotency_key']??null,$afterMismatchRepeat['idempotency_key']??null,'The same mismatch episode must remain idempotent across repeated finance refreshes.');

echo "classic-fba-support-open-idempotency-test: OK\n";
