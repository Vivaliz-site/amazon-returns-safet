<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';

function pclSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}

$now=new DateTimeImmutable('2026-09-08 14:00:00',new DateTimeZone('UTC'));
$case=[
    'id'=>23,'amazon_order_id'=>'702-2724498-6237004','program'=>'DELIVERY_BY_AMAZON',
    'safe_t_id'=>'44235-16847-8784913','state'=>'CREDIT_PENDING','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-06-24 15:01:01','seller_debit_at'=>'2026-06-24 15:01:01',
    'expected_reimbursement_amount'=>'109.01','reconciled_credit_amount'=>'64.05',
    'appeal_deadline_at'=>null,
];
$promise=[
    'id'=>1,'case_id'=>23,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-05 15:31:06','payload'=>[
        'safe_t_id'=>'44235-16847-8784913','claim_status'=>'APPROVED','appeal_submitted'=>false,
        'appeal_deadline_at'=>'2026-09-08 12:59:00',
        'decision_text'=>'Voce sera reembolsado proativamente ate 12 August 2026. Caso voce nao receba o reembolso no prazo mencionado, apresente um recurso referente a esta reivindicacao.',
    ],
];
$finance=[
    'id'=>2,'case_id'=>23,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-08 13:59:00','payload'=>[
        'refresh_complete'=>true,'credit_amount'=>'64.05','outstanding_amount'=>'44.96',
        'unclassified_transactions'=>4,'ambiguous_reimbursement_transactions'=>0,
        'unsettled_financial_evidence'=>false,
    ],
];
$decision=SvAmazonReturnActionRouter::decide($case,[$promise,$finance],['eligible'=>false],$now);
pclSame('SAFE_T_APPEAL',$decision['action']??null,'Expired proactive promise with verified unpaid seller balance must use the official status-event appeal deadline when the case projection lags');
pclSame('MISSED_APPEAL_WINDOW_RECOVERY_ATTEMPT',$decision['reason']??null,'Recovery attempt must remain auditable');

$accepted=[
    'id'=>3,'case_id'=>23,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-08 13:30:00','payload'=>[
        'action'=>'SAFE_T_APPEAL','status'=>'ACCEPTED','submitted'=>true,
        'external_id'=>'44235-16847-8784913',
    ],
];
$already=SvAmazonReturnActionRouter::decide($case,[$promise,$accepted,$finance],['eligible'=>false],$now);
pclSame('WAIT',$already['action']??null,'An accepted Seller Central appeal result must suppress duplicate appeal submission even if an older status still says appeal_submitted=false');
pclSame('APPEAL_ALREADY_SUBMITTED_AWAITING_RESPONSE',$already['reason']??null,'Duplicate suppression must remain auditable');
echo "proactive-credit-expiry-lifecycle-test: OK\n";
