<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function sseafSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual){
        throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
    }
}
function sseafAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$engine=new SvAmazonSafeTDecisionEngine();
$case=[
    'id'=>13254,
    'amazon_order_id'=>'702-7802983-5785045',
    'program'=>'FBA',
    'safe_t_id'=>'27845-46811-9805451',
    'support_case_id'=>'21839077561',
    'state'=>'SUPPORT_ESCALATION',
    'physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-06-24 14:41:00',
    'seller_debit_at'=>'2026-06-24 14:41:00',
    'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE',
    'appeal_deadline_at'=>'2026-08-18 14:41:00',
    'latest_denial_text'=>'A Amazon orientou recorrer pela SAFE-T.',
];
$timeline=[[
    'id'=>2004,
    'case_id'=>13254,
    'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED',
    'source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-16 14:30:00',
    'payload'=>[
        'case_id'=>'21839077561',
        'case_status'=>'RESOLVED',
        'latest_text'=>'A resolução deve ser recorrida diretamente pela SAFE-T. O Seller Support não pode interferir.',
    ],
]];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$now=new DateTimeImmutable('2026-09-16 15:00:00',new DateTimeZone('UTC'));

$decision=$engine->nextAction($case,$timeline,$policy,$now);
sseafSame('SELLER_SUPPORT_OPEN',$decision['action']??null,'An expired support-directed SAFE-T appeal must open a fresh Seller Support case because terminal cases cannot be reopened.');
sseafSame('SUPPORT_RESOLUTION_APPEAL_WINDOW_UNAVAILABLE',$decision['reason']??null,'The fallback must preserve the precise recovery reason.');
sseafSame('GENERAL_ORDER_SUPPORT',$decision['support_route']??null,'Expired SAFE-T appeal recovery must use the deterministic general support route.');
sseafSame('21839077561',$decision['previous_support_case_id']??null,'The previous terminal Seller Support case must remain linked as evidence for the new recovery case.');
sseafAssert(preg_match('/^[a-f0-9]{64}$/',(string)($decision['idempotency_key']??''))===1,'Seller Support recovery write must be idempotent.');

echo "support-resolution-expired-appeal-fallback-test: OK\n";
