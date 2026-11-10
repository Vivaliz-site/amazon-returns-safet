<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
function sbAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('SIBLING_SUPPORT_RECONCILIATION: '.$why);}
$engine=new SvAmazonSafeTDecisionEngine();
$now=new DateTimeImmutable('2026-11-10 20:30:00',new DateTimeZone('UTC'));
$policy=['eligible'=>true,'state'=>'SAFE_T_ELIGIBLE','policy_version_id'=>31];
$case=[
 'id'=>13216,'amazon_order_id'=>'702-9737360-6769805','program'=>'FBA_ONSITE',
 'state'=>'APPEAL_DENIED_FINAL','physical_status'=>'NOT_RECEIVED',
 'safe_t_id'=>'10064-30094-1469107','support_case_id'=>'22403672081',
 'refund_at'=>'2026-08-17 18:02:19','seller_debit_at'=>'2026-08-17 18:02:19',
 'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','refund_amount'=>'68.29',
 'expected_reimbursement_amount'=>'68.29','reconciled_credit_amount'=>'53.07',
 'latest_denial_text'=>'Recurso SAFE-T negado e encerrado.',
];
$stale=[
 'id'=>1040467,'case_id'=>13216,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT',
 'source'=>'SELLER_CENTRAL','occurred_at'=>'2026-10-16 11:13:14',
 'payload'=>['action'=>'SELLER_SUPPORT_UPDATE','status'=>'HUMAN_INTERVENTION_REQUIRED',
  'reason'=>'SELLER_SUPPORT_CHAT_READ_BACK_NOT_CONFIRMED','external_id'=>null],
];
$activeSibling=[
 'id'=>1249000,'case_id'=>13216,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED',
 'source'=>'SELLER_CENTRAL','occurred_at'=>'2026-11-09 03:11:12',
 'payload'=>['case_id'=>'22632279381','case_status'=>'PENDINGAMAZONACTION',
  'latest_text'=>'Equipe especializada está analisando seu recurso. Não é necessária ação adicional.'],
];
$blocked=$engine->nextAction($case,[$stale],$policy,$now);
sbAssert(($blocked['action']??'')==='BLOCKED_REVIEW','Older ambiguous write must remain visible when no official readback exists.');
$decision=$engine->nextAction($case,[$stale,$activeSibling],$policy,$now);
sbAssert(($decision['action']??'')==='WAIT','Authenticated alternate support thread active must clear obsolete readback blocker.');
sbAssert(($decision['reason']??'')==='SUPPORT_ESCALATION_ALREADY_ACTIVE','Awaiting Amazon action must be grounded in newest real thread.');
sbAssert(($decision['support_case_id']??'')==='22632279381','Do not keep using obsolete primary support ID.');
$wrongCase=$activeSibling;$wrongCase['case_id']=13211;
sbAssert(($engine->nextAction($case,[$stale,$wrongCase],$policy,$now)['action']??'')==='BLOCKED_REVIEW',
 'A different case cannot clear an uncertain external attempt.');
$emailOnly=$activeSibling;$emailOnly['event_type']='SELLER_SUPPORT_EMAIL';$emailOnly['source']='GMAIL';
sbAssert(($engine->nextAction($case,[$stale,$emailOnly],$policy,$now)['action']??'')==='BLOCKED_REVIEW',
 'Email reference alone is not a Seller Central readback.');
$older=$activeSibling;$older['occurred_at']='2026-10-15 03:11:12';
sbAssert(($engine->nextAction($case,[$stale,$older],$policy,$now)['action']??'')==='BLOCKED_REVIEW',
 'A support observation before the uncertain attempt is insufficient.');
$resolvedSibling=$activeSibling;$resolvedSibling['payload']=[
 'case_id'=>'22632279381','case_status'=>'RESOLVED',
 'latest_text'=>'Reembolso ainda em processamento. Consulte o financeiro antes de abrir nova solicitação.',
];
$afterResolved=$engine->nextAction($case,[$stale,$resolvedSibling],$policy,$now);
sbAssert(($afterResolved['reason']??'')!=='SELLER_SUPPORT_CHAT_READ_BACK_NOT_CONFIRMED',
 'A later verified sibling response must supersede an older bridge-level uncertainty.');
echo "support-sibling-stale-blocker-test: OK\n";
