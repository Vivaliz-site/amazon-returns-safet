<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-09-07 18:05:00',new DateTimeZone('UTC'));
$case=[
  'id'=>501,'amazon_order_id'=>'702-0707321-6872209','safe_t_id'=>null,
  'state'=>'POLICY_REVIEW_REQUIRED','refund_at'=>'2026-05-06 00:04:03',
  'refund_initiator'=>'UNKNOWN','physical_status'=>'NOT_RECEIVED',
  'expected_reimbursement_amount'=>'112.50','reconciled_credit_amount'=>'111.25',
];
$timeline=[[
  'id'=>1,'case_id'=>501,'event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','source'=>'SP_API_FINANCES',
  'occurred_at'=>'2026-09-07 18:00:00','payload'=>['transaction'=>[
    'transaction_type'=>'Adjustment','transaction_status'=>'RELEASED','description'=>'SERRACReimbursement',
    'total_amount'=>['amount'=>'111.25','currency'=>'BRL'],
  ]],
]];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED','policy_version_id'=>null,'eligibility_at'=>null];
$decision=$engine->nextAction($case,$timeline,$policy,$now);
$errors=[];
if(($decision['action']??null)!=='CHECK_FINANCES')$errors[]='Existing seller reimbursement must suppress refund-initiator human review. got='.json_encode($decision);
if(($decision['reason']??null)!=='PARTIAL_REIMBURSEMENT_VERIFY_BEFORE_NEW_CLAIM')$errors[]='Automatic finance verification must be auditable. got='.json_encode($decision);
$unpaid=$case;$unpaid['reconciled_credit_amount']='0.00';
$blocked=$engine->nextAction($unpaid,[],$policy,$now);
if(($blocked['action']??null)!=='BLOCKED_REVIEW')$errors[]='Unknown initiator without any reimbursement evidence must remain conservative. got='.json_encode($blocked);
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "partial-reimbursement-no-review-test: OK\n";
