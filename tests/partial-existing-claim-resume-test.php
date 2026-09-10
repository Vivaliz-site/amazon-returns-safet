<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ReturnActionRouter.php';

function pecrSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));
}

$now=new DateTimeImmutable('2026-09-08 14:26:00',new DateTimeZone('UTC'));
$case=[
    'id'=>19,'amazon_order_id'=>'701-4306982-6000233','program'=>'FBA',
    'safe_t_id'=>'52214-19729-8255607','state'=>'CREDIT_PENDING','physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-07-13 23:34:59','seller_debit_at'=>'2026-07-13 23:34:59',
    'expected_reimbursement_amount'=>'68.57','reconciled_credit_amount'=>'46.96',
    'appeal_deadline_at'=>null,
];
$status=[
    'id'=>1,'case_id'=>19,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-07 21:31:27','payload'=>[
        'safe_t_id'=>'52214-19729-8255607','claim_status'=>'APPROVED','appeal_submitted'=>false,
        'appeal_deadline_at'=>'2026-09-08 16:08:00','decision_text'=>null,
    ],
];
$finance=[
    'id'=>2,'case_id'=>19,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-08 14:01:33','payload'=>[
        'refresh_complete'=>true,'credit_amount'=>'46.96','outstanding_amount'=>'21.61',
        'unclassified_transactions'=>4,'ambiguous_reimbursement_transactions'=>0,
        'unsettled_financial_evidence'=>false,
    ],
];
$decision=SvAmazonReturnActionRouter::decide($case,[$status,$finance],['eligible'=>false],$now);
pecrSame('SAFE_T_APPEAL',$decision['action']??null,'Existing approved SAFE-T with material verified unpaid balance must resume through appeal');
pecrSame('PARTIAL_REIMBURSEMENT_BALANCE_APPEAL_REQUIRED',$decision['reason']??null,'Partial-balance recovery must remain auditable');

$accepted=[
    'id'=>3,'case_id'=>19,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-08 14:10:00','payload'=>[
        'action'=>'SAFE_T_APPEAL','status'=>'ACCEPTED','submitted'=>true,'external_id'=>'52214-19729-8255607',
    ],
];
$duplicate=SvAmazonReturnActionRouter::decide($case,[$status,$accepted,$finance],['eligible'=>false],$now);
pecrSame('WAIT',$duplicate['action']??null,'Accepted appeal must suppress duplicate partial-balance appeal');
pecrSame('APPEAL_ALREADY_SUBMITTED_AWAITING_RESPONSE',$duplicate['reason']??null,'Duplicate suppression must remain auditable');
$staleFinance=$finance;
$staleFinance["id"]=4;
$staleFinance["occurred_at"]="2026-09-08 11:00:00";
$stale=SvAmazonReturnActionRouter::decide($case,[$status,$staleFinance],["eligible"=>false],$now);
pecrSame("CHECK_FINANCES",$stale["action"]??null,"Approved partial SAFE-T must request a fresh financial check instead of silently waiting when evidence is stale");
pecrSame("APPROVED_PARTIAL_CREDIT_VERIFY_FINANCES",$stale["reason"]??null,"Stale partial-balance verification must remain auditable");
echo "partial-existing-claim-resume-test: OK\n";
