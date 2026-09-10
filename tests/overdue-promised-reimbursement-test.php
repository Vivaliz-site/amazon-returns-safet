<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';
function oprSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
function oprAssert(bool $condition,string $why):void{if(!$condition)throw new RuntimeException($why);}
$now=new DateTimeImmutable('2026-09-05T15:00:00Z');
$claim='11111-22222-3333333';
$case=['id'=>77,'amazon_order_id'=>'702-1111111-2222222','safe_t_id'=>$claim,'program'=>'STANDARD','refund_at'=>'2026-06-01 12:00:00','seller_debit_at'=>'2026-06-01 12:00:00','physical_status'=>'NOT_RECEIVED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','state'=>'APPEAL_SUBMITTED','appeal_deadline_at'=>'2026-09-02 18:00:00'];
$policy=['eligible'=>true,'eligibility_at'=>'2026-07-16 12:00:00','eligibility_days'=>45];
$events=[
 ['id'=>1,'case_id'=>77,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-01 12:00:00','payload'=>['action'=>'SAFE_T_APPEAL','safe_t_id'=>$claim,'status'=>'ACCEPTED','submitted'=>true,'external_id'=>$claim]],
 ['id'=>2,'case_id'=>77,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-09-02 12:00:00','payload'=>['claim_status'=>'PENDING','safe_t_id'=>$claim,'decision_text'=>'Voce sera reembolsado proativamente ate 4 de setembro de 2026.']],
 ['id'=>3,'case_id'=>77,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES','occurred_at'=>'2026-09-05 14:00:00','payload'=>['refresh_complete'=>true,'credit_amount'=>'0.00','outstanding_amount'=>'100.00','unclassified_transactions'=>0,'ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false]],
];
$decision=SvAmazonReturnActionRouter::decide($case,$events,$policy,$now);
oprSame('SAFE_T_APPEAL',$decision['action']??null,'Expired unpaid Amazon promise must charge the existing SAFE-T even after an accepted prior appeal.');
oprSame('PROMISED_REIMBURSEMENT_OVERDUE_FOLLOW_UP',$decision['reason']??null,'Promise-specific follow-up reason.');
oprSame('2026-09-04',$decision['promised_by_date']??null,'Original promised calendar date must be preserved.');
oprSame('100.00',(string)($decision['outstanding_amount']??''),'Confirmed outstanding amount must be carried into the write decision.');
oprAssert(preg_match('/^[a-f0-9]{64}$/',(string)($decision['idempotency_key']??''))===1,'Promise follow-up requires deterministic idempotency.');
$payload=SvAmazonExternalWritePayload::build($decision,$case,$events);
$narrative=(string)($payload['write_snapshot']['narrative']??'');
oprAssert(str_contains($narrative,'mesma SAFE-T'),'Write must explicitly request payment in the existing SAFE-T.');
oprAssert(str_contains($narrative,'R$ 100,00'),'Write must state the outstanding amount.');
oprAssert(str_contains($narrative,'04/09/2026'),'Write must state the promised date.');
echo "overdue-promised-reimbursement-test: OK\n";