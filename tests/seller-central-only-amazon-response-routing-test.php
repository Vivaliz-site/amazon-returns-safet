<?php
declare(strict_types=1);

require_once __DIR__.'/../workers/amazon-returns/scheduler.php';

function scorSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function scorAssert(bool $value,string $message):void{
    if(!$value)throw new RuntimeException($message);
}

$now=new DateTimeImmutable('2026-10-07 04:00:00',new DateTimeZone('UTC'));
$case=[
    'id'=>13229,
    'amazon_order_id'=>'702-1808735-0608208',
    'safe_t_id'=>'37495-60041-8543443',
    'support_case_id'=>'22144771981',
    'refund_at'=>'2026-08-03 18:22:13',
    'expected_reimbursement_amount'=>'68.57',
    'reconciled_credit_amount'=>'0.00',
];
$review=[
    'action'=>'SAFE_T_EMAIL_REVIEW',
    'reason'=>'APPEAL_DENIED_REQUIRES_DETAILED_EMAIL_REVIEW',
    'idempotency_key'=>hash('sha256','review-original'),
];
$routed=SvAmazonReturnsScheduler::normalizeRecoveryChannel($case,$review,$now);
scorSame('SELLER_SUPPORT_UPDATE',$routed['action']??null,'A SAFE-T review must use the existing Seller Central support case.');
scorSame('22144771981',$routed['support_case_id']??null,'Existing Seller Support case must be reused.');
scorSame('SAFE_T_REVIEW_ESCALATED_VIA_SELLER_CENTRAL',$routed['reason']??null,'Seller Central escalation reason must be explicit.');
scorSame('seller_central_bridge',SvAmazonReturnsScheduler::dependencyForAction((string)($routed['action']??'')),'Routed review must depend on Seller Central, never Gmail.');
scorAssert(($routed['idempotency_key']??'')!==$review['idempotency_key'],'Channel migration must receive a new deterministic idempotency key.');

$unbound=$case;
$unbound['id']=14;
$unbound['support_case_id']=null;
$unbound['refund_at']='2026-07-22 11:51:02';
$open=SvAmazonReturnsScheduler::normalizeRecoveryChannel($unbound,$review,$now);
scorSame('SELLER_SUPPORT_OPEN',$open['action']??null,'A SAFE-T review without a support case must open Seller Support in Seller Central.');
scorSame('GENERAL_ORDER_SUPPORT',$open['support_route']??null,'New Seller Support escalation must use the general order route.');

$reply=[
    'action'=>'SAFE_T_EMAIL_REPLY',
    'reason'=>'AMAZON_REQUESTED_DATE_REACHED_UNRECOVERED',
    'idempotency_key'=>hash('sha256','reply-original'),
];
$replyRouted=SvAmazonReturnsScheduler::normalizeRecoveryChannel($case,$reply,$now);
scorSame('SELLER_SUPPORT_UPDATE',$replyRouted['action']??null,'A SAFE-T email reply must also stay inside Seller Central.');
scorSame('SAFE_T_FOLLOWUP_ESCALATED_VIA_SELLER_CENTRAL',$replyRouted['reason']??null,'Follow-up routing reason must be explicit.');

echo "seller-central-only-amazon-response-routing-test: OK\n";
