<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function ssdrSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
function ssdrAssert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

$case=[
    'id'=>13225,'amazon_order_id'=>'702-6823050-9173862','program'=>'FBA',
    'state'=>'SUPPORT_ESCALATION','safe_t_id'=>null,'support_case_id'=>'22426419421',
    'physical_status'=>'NOT_RECEIVED','quantity_received'=>0,'quantity_refunded'=>1,
    'refund_at'=>'2026-09-30 00:00:00','seller_debit_at'=>'2026-09-30 00:00:00',
    'refund_initiator'=>'UNKNOWN','expected_reimbursement_amount'=>'46.60','reconciled_credit_amount'=>'0.00',
];
$old=[
    'id'=>825664,'case_id'=>13225,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-02 16:00:58','payload'=>[
        'case_id'=>'22354106631','case_status'=>'RESOLVED',
        'latest_text'=>'Because we haven’t received a response from you, we assume that your issue is resolved. We have now closed this case. If the issue is not resolved, you can reopen this case and provide the requested information. Original request: Poderia nos confirmar se o item foi recebido de volta?',
    ],
];
$current=[
    'id'=>867738,'case_id'=>13225,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-06 00:07:06','payload'=>[
        'case_id'=>'22426419421','case_status'=>'RESOLVED',
        'latest_text'=>'Este caso foi fechado porque parece ser a mesma solicitação de um caso anterior que você abriu. ID do caso anterior: 22354106631',
    ],
];
$engine=new SvAmazonSafeTDecisionEngine();
$decision=$engine->nextAction($case,[$old,$current],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-10-08 03:20:00',new DateTimeZone('UTC')));
ssdrSame('SELLER_SUPPORT_UPDATE',$decision['action']??null,'Duplicate closure must preserve the unanswered seller action.');
ssdrSame('SUPPORT_REQUESTED_SELLER_RESPONSE',$decision['reason']??null,'Duplicate closure must inherit the missing-response reason.');
ssdrSame('22426419421',$decision['support_case_id']??null,'Recovery must use the current recent support binding.');
ssdrAssert(str_contains((string)($decision['support_latest_text']??''),'recebido de volta'),'Recovery must carry the referenced unanswered question.');

$resolvedReference=$old;
$resolvedReference['id']=825665;
$resolvedReference['occurred_at']='2026-10-03 10:00:00';
$resolvedReference['payload']['case_status']='RESOLVED';
$resolvedReference['payload']['latest_text']='A investigação deste caso foi concluída e não há ação adicional pendente do vendedor.';
$resolvedDecision=$engine->nextAction($case,[$old,$resolvedReference,$current],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-10-08 03:20:00',new DateTimeZone('UTC')));
ssdrAssert(($resolvedDecision['action']??null)!=='SELLER_SUPPORT_UPDATE','A newer resolved observation on the referenced case must suppress replay of an older unanswered prompt.');

$acceptedReply=[
    'id'=>825666,'case_id'=>13225,'event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-03 11:00:00','payload'=>[
        'action'=>'SELLER_SUPPORT_UPDATE','status'=>'ACCEPTED','submitted'=>true,'external_id'=>'22354106631',
    ],
];
$answeredDecision=$engine->nextAction($case,[$old,$acceptedReply,$current],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-10-08 03:20:00',new DateTimeZone('UTC')));
ssdrAssert(($answeredDecision['action']??null)!=='SELLER_SUPPORT_UPDATE','A confirmed outbound reply on the referenced case must suppress replay of the older unanswered prompt.');

echo "seller-support-duplicate-redirect-test: OK\n";
