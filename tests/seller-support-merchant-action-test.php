<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';

function ssmaSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function ssmaAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$merchantText='This is a reminder to let you know that we need more information to resolve your case. '
    .'If you still need assistance, respond to this message and provide the details we requested below.';

$merchantResolution=SvAmazonSellerSupportStatus::resolution([
    'case_id'=>'22321391191',
    'case_status'=>'PENDINGMERCHANTACTION',
    'latest_text'=>$merchantText,
]);
ssmaSame('SELLER_ACTION_REQUIRED',$merchantResolution,'Amazon PENDINGMERCHANTACTION must be treated as an explicit seller response obligation.');

$closedResolution=SvAmazonSellerSupportStatus::resolution([
    'case_id'=>'22321391191',
    'case_status'=>'RESOLVED',
    'latest_text'=>'Because we haven’t received a response from you, we assume that your issue is resolved. '
        .'We have now closed this case. If the issue is not resolved, you can reopen this case and provide the requested information.',
]);
ssmaSame('SELLER_ACTION_REQUIRED',$closedResolution,'A support case closed only because the seller did not answer must still require immediate recovery.');

$case=[
    'id'=>13221,
    'amazon_order_id'=>'701-1480606-9517055',
    'program'=>'FBA',
    'safe_t_id'=>null,
    'support_case_id'=>'22321391191',
    'state'=>'SUPPORT_ESCALATION',
    'physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-08-13 23:02:28',
    'seller_debit_at'=>'2026-08-13 23:02:28',
    'refund_initiator'=>'UNKNOWN',
    'expected_reimbursement_amount'=>'68.57',
    'reconciled_credit_amount'=>'0.00',
];
$policy=['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'];
$merchantObserved=[
    'id'=>800559,
    'case_id'=>13221,
    'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED',
    'source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-29 20:03:59',
    'payload'=>[
        'case_id'=>'22321391191',
        'case_status'=>'PENDINGMERCHANTACTION',
        'latest_text'=>$merchantText,
    ],
];
$engine=new SvAmazonSafeTDecisionEngine();
$decision=$engine->nextAction($case,[$merchantObserved],$policy,new DateTimeImmutable('2026-09-29 20:10:00',new DateTimeZone('UTC')));
ssmaSame('SELLER_SUPPORT_UPDATE',$decision['action']??null,'A live merchant-action request must update the existing Seller Support case.');
ssmaSame('SUPPORT_REQUESTED_SELLER_RESPONSE',$decision['reason']??null,'Seller-action follow-up must have a dedicated deterministic reason.');
ssmaSame('22321391191',$decision['support_case_id']??null,'The response must stay in the same Seller Support case.');
ssmaAssert(preg_match('/^[a-f0-9]{64}$/',(string)($decision['idempotency_key']??''))===1,'Seller-action response must be idempotent.');

$missingReturnCase=$case;
$missingReturnCase['physical_status']='NOT_RECEIVED';
$missingReturnCase['quantity_received']=0;
$missingReturnCase['quantity_refunded']=2;
$missingReturnRequest=[
    'id'=>804612,
    'case_id'=>13221,
    'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED',
    'source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-10-06 12:00:00',
    'payload'=>[
        'case_id'=>'22403466041',
        'case_status'=>'PENDINGMERCHANTACTION',
        'latest_text'=>'Por favor, confirme qual situação se aplica: a unidade foi danificada após a devolução ou a unidade não retornou ao seu estoque após a devolução?',
    ],
];
$missingReturnDecision=$engine->nextAction($missingReturnCase,[$missingReturnRequest],$policy,new DateTimeImmutable('2026-10-06 12:05:00',new DateTimeZone('UTC')));
ssmaSame('SELLER_SUPPORT_UPDATE',$missingReturnDecision['action']??null,'Missing-return clarification must produce a Seller Support update.');
ssmaSame('22403466041',$missingReturnDecision['support_case_id']??null,'Missing-return clarification must target the exact sibling case.');
$missingReturnPayload=SvAmazonExternalWritePayload::build($missingReturnDecision,$missingReturnCase,[$missingReturnRequest]);
$missingReturnNarrative=(string)($missingReturnPayload['write_snapshot']['narrative']??'');
ssmaAssert(str_contains($missingReturnNarrative,'não retornaram ao nosso estoque'),'Trusted NOT_RECEIVED state must answer that the units did not return.');
ssmaAssert(str_contains($missingReturnNarrative,'Não estamos informando dano após recebimento'),'The answer must explicitly reject the damaged-after-receipt alternative.');

$physicalReturnQuestionCase=$case;
$physicalReturnQuestionCase['physical_status']='NOT_RECEIVED';
$physicalReturnQuestionCase['quantity_received']=0;
$physicalReturnQuestionCase['quantity_refunded']=1;
$physicalReturnQuestionDecision=[
    'action'=>'SELLER_SUPPORT_UPDATE',
    'reason'=>'SUPPORT_REQUESTED_SELLER_RESPONSE',
    'case_id'=>13225,
    'support_case_id'=>'22426419421',
    'support_latest_text'=>'Para que possamos confirmar se há algum valor adicional a ser ressarcido, precisamos entender se o produto retornou fisicamente ao seu centro de distribuição após a tentativa de entrega. Poderia nos confirmar se o item foi recebido de volta?',
];
$physicalReturnQuestionPayload=SvAmazonExternalWritePayload::build($physicalReturnQuestionDecision,$physicalReturnQuestionCase,[]);
$physicalReturnQuestionNarrative=(string)($physicalReturnQuestionPayload['write_snapshot']['narrative']??'');
ssmaAssert(str_contains($physicalReturnQuestionNarrative,'não retornou ao nosso estoque') || str_contains($physicalReturnQuestionNarrative,'não recebemos fisicamente'),'A direct physical-return question must be answered from trusted NOT_RECEIVED evidence, not with a generic finance-only reply.');

$returnMentionDecision=$physicalReturnQuestionDecision;
$returnMentionDecision['support_latest_text']='O item retornou ao centro de distribuição em 01/10/2026. Para prosseguir com a análise, envie a nota fiscal de compra.';
$returnMentionPayload=SvAmazonExternalWritePayload::build($returnMentionDecision,$physicalReturnQuestionCase,[]);
$returnMentionNarrative=(string)($returnMentionPayload['write_snapshot']['narrative']??'');
ssmaAssert(!str_contains($returnMentionNarrative,'não retornou ao nosso estoque') && !str_contains($returnMentionNarrative,'não retornaram ao nosso estoque'),'A mere statement that an item returned must not be misclassified as a physical-return question.');

$evidenceRequestCase=$case;
$evidenceRequestCase['safe_t_id']='93434-86383-4098666';
$evidenceRequestCase['physical_status']='NOT_RECEIVED';
$evidenceRequestCase['quantity_received']=0;
$evidenceRequestCase['quantity_refunded']=1;
$evidenceRequestCase['expected_reimbursement_amount']='485.75';
$evidenceRequestCase['reconciled_credit_amount']='262.85';
$evidenceRequestDecision=[
    'action'=>'SELLER_SUPPORT_UPDATE',
    'reason'=>'SUPPORT_REQUESTED_SELLER_RESPONSE',
    'case_id'=>13227,
    'support_case_id'=>'22449931941',
    'support_latest_text'=>'Confirmamos que a sua solicitação de revisão foi registrada com sucesso. Para prosseguirmos, precisamos das seguintes informações: '
        .'1. Evidências de que a devolução não foi recebida, como registros de logística ou declaração escrita. '
        .'2. Confirmação do endereço de entrega cadastrado na sua conta no período do pedido. Retorne a este caso assim que possível.',
];
$evidenceRequestPayload=SvAmazonExternalWritePayload::build($evidenceRequestDecision,$evidenceRequestCase,[]);
$evidenceRequestNarrative=(string)($evidenceRequestPayload['write_snapshot']['narrative']??'');
ssmaAssert(str_contains($evidenceRequestNarrative,'não recebemos fisicamente'),'A request for non-receipt evidence must answer with the trusted physical non-receipt fact.');
ssmaAssert(str_contains($evidenceRequestNarrative,'quantidade recebida permanece 0'),'The reply must state the persisted zero received quantity as evidence.');
ssmaAssert(mb_stripos($evidenceRequestNarrative,'não temos no registro deste caso o endereço histórico',0,'UTF-8')!==false,'The reply must not invent a historical seller return address that is absent from case evidence.');
ssmaAssert(str_contains($evidenceRequestNarrative,'endereço de devolução efetivamente utilizado'),'The reply must ask Amazon for the exact return address it actually used when historical address evidence is unavailable.');
ssmaAssert(str_contains($evidenceRequestNarrative,'R$ 222,90'),'The reply must preserve the current unreconciled seller balance.');

$closedObserved=$merchantObserved;
$closedObserved['id']=804604;
$closedObserved['occurred_at']='2026-09-30 08:02:08';
$closedObserved['payload']['case_status']='RESOLVED';
$closedObserved['payload']['latest_text']='Because we haven’t received a response from you, we assume that your issue is resolved. '
    .'We have now closed this case. If the issue is not resolved, you can reopen this case and provide the requested information.';
$closedDecision=$engine->nextAction($case,[$closedObserved],$policy,new DateTimeImmutable('2026-09-30 08:05:00',new DateTimeZone('UTC')));
ssmaSame('SELLER_SUPPORT_UPDATE',$closedDecision['action']??null,'A case closed for missing seller response must be reopened/updated, not silently accepted as resolved.');
ssmaSame('SUPPORT_REQUESTED_SELLER_RESPONSE',$closedDecision['reason']??null,'Missing-response closure must reuse the same recovery reason.');
ssmaSame('22321391191',$closedDecision['support_case_id']??null,'Recovery must target the original support case.');

$payload=SvAmazonExternalWritePayload::build($closedDecision,$case,[$closedObserved]);
$narrative=(string)($payload['write_snapshot']['narrative']??'');
ssmaAssert(str_contains($narrative,'Nós estamos solicitando o nosso ressarcimento como vendedores.'),'Seller Support reply must be written in first person.');
ssmaAssert(str_contains($narrative,'R$ 68,57'),'Seller Support reply must include the outstanding seller reimbursement amount.');
ssmaAssert(str_contains($narrative,'não estamos reportando uma mensagem de erro'),'Generic information request must explain why an error screenshot is not applicable for this reimbursement issue.');
ssmaAssert(!str_contains($narrative,'SUPPORT_REQUESTED_SELLER_RESPONSE'),'Internal reason codes must never leak into external copy.');

$humanIntervention=[
    'id'=>804605,
    'case_id'=>13221,
    'event_type'=>'SELLER_CENTRAL_ACTION_RESULT',
    'source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-30 08:06:00',
    'payload'=>[
        'action'=>'SELLER_SUPPORT_UPDATE',
        'status'=>'HUMAN_INTERVENTION_REQUIRED',
        'submitted'=>false,
        'external_id'=>'22321391191',
        'retry_safe'=>false,
        'reason'=>'SUPPORT_CASE_NOT_REOPENABLE',
    ],
];
$blockedDecision=$engine->nextAction($case,[$closedObserved,$humanIntervention],$policy,new DateTimeImmutable('2026-09-30 08:07:00',new DateTimeZone('UTC')));
ssmaSame('BLOCKED_REVIEW',$blockedDecision['action']??null,'A non-reopenable Seller Support result must remain visible as a review blocker instead of silently rescheduling or disappearing.');
ssmaSame('SUPPORT_CASE_NOT_REOPENABLE',$blockedDecision['reason']??null,'The review blocker must preserve the exact Seller Support reason.');

$freshAfterBlock=$merchantObserved;
$freshAfterBlock['id']=804606;
$freshAfterBlock['occurred_at']='2026-09-30 09:00:00';
$freshAfterBlock['payload']['case_status']='PENDINGMERCHANTACTION';
$freshAfterBlock['payload']['latest_text']=$merchantText;
$freshDecision=$engine->nextAction($case,[$closedObserved,$humanIntervention,$freshAfterBlock],$policy,new DateTimeImmutable('2026-09-30 09:01:00',new DateTimeZone('UTC')));
ssmaSame('SELLER_SUPPORT_UPDATE',$freshDecision['action']??null,'A newer Seller Support observation must clear the older human-intervention blocker and allow the current case to be reevaluated.');

echo "seller-support-merchant-action-test: OK\n";
