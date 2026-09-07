<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-07 17:10:00',new DateTimeZone('UTC'));
$case=[
    'id'=>505,
    'amazon_order_id'=>'702-8373627-4388208',
    'safe_t_id'=>'84164-27415-4505005',
    'state'=>'SAFE_T_DENIED',
    'appeal_deadline_at'=>'2026-06-29 16:35:00',
    'expected_reimbursement_amount'=>'136.96',
    'reconciled_credit_amount'=>'0.00',
    'refund_at'=>'2026-05-06 08:32:36',
    'refund_initiator'=>'UNKNOWN',
    'physical_status'=>'NOT_RECEIVED',
];
$timeline=[[
    'id'=>1,'case_id'=>505,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-07 04:48:04',
    'payload'=>[
        'safe_t_id'=>'84164-27415-4505005','claim_status'=>'DENIED','appeal_submitted'=>false,
        'decision_text'=>'A Amazon prometeu reembolso proativo até Jun 24 2026. Se não receber, recorra desta reivindicação.',
        'appeal_deadline_at'=>'2026-06-29 16:35:00',
    ],
]];
$policy=['eligible'=>true,'state'=>'SAFE_T_ELIGIBLE','policy_version_id'=>7441,'eligibility_at'=>'2026-06-20 08:32:36'];
$decision=$engine->nextAction($case,$timeline,$policy,$now);
$errors=[];
if(($decision['action']??null)!=='SAFE_T_APPEAL')$errors[]='Expired internal appeal window must trigger one recovery appeal attempt, not human review. got='.json_encode($decision);
if(($decision['reason']??null)!=='MISSED_APPEAL_WINDOW_RECOVERY_ATTEMPT')$errors[]='Recovery attempt must be auditable. got='.json_encode($decision);
$case['state']='APPEAL_SUBMITTED';
$again=$engine->nextAction($case,$timeline,$policy,$now);
if(($again['action']??null)==='SAFE_T_APPEAL')$errors[]='Already submitted appeal must never be duplicated.';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "expired-appeal-recovery-test: OK\n";
