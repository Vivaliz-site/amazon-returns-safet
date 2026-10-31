<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';

function srnrSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function srnrAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$case=[
    'id'=>4,
    'amazon_order_id'=>'701-8088549-4857851',
    'program'=>'FBA',
    'safe_t_id'=>'81342-77571-9071754',
    'support_case_id'=>'21839072101',
    'support_case_status'=>'RESOLVED',
    'state'=>'SUPPORT_ESCALATION',
    'physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-07-02 23:15:23',
    'seller_debit_at'=>'2026-07-02 23:15:23',
    'refund_initiator'=>'AMAZON_AUTOMATIC',
    'expected_reimbursement_amount'=>'22.80',
    'reconciled_credit_amount'=>'0.00',
];
$finance=[
    'id'=>1,'case_id'=>4,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-26 00:35:01',
    'payload'=>['refresh_complete'=>true,'outstanding_amount'=>'22.80','ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false],
];
$support=[
    'id'=>2,'case_id'=>4,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-26 00:36:00',
    'payload'=>[
        'case_id'=>'21839072101','case_status'=>'RESOLVED',
        'latest_text'=>'O comprador foi reembolsado, mas o produto não retornou ao seu estoque. Após investigação, foi identificado que o item foi devolvido em 11/07/2026. A reivindicação SAFE-T foi registrada fora do período elegível de 7 dias contados a partir da data de devolução.',
    ],
];

srnrSame('RETURN_NOT_RECEIVED_DISPUTE',SvAmazonSellerSupportStatus::resolution($support['payload']),'Support must distinguish an asserted return from actual seller receipt when the same case says the product did not return.');

$engine=new SvAmazonSafeTDecisionEngine();
$decision=$engine->nextAction($case,[$finance,$support],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-09-26T00:40:00Z'));
srnrSame('SELLER_SUPPORT_UPDATE',$decision['action']??null,'Contradictory returned-date denial must be answered in the existing Seller Support case.');
srnrSame('SUPPORT_RETURN_NOT_RECEIVED_REBUTTAL',$decision['reason']??null,'Contradictory return assertion must use the dedicated rebuttal reason.');
srnrSame('21839072101',$decision['support_case_id']??null,'Reply must stay in the same Seller Support case.');
srnrAssert(preg_match('/^[a-f0-9]{64}$/',(string)($decision['idempotency_key']??''))===1,'Rebuttal must be idempotent.');

$narrative=(string)(SvAmazonExternalWritePayload::build($decision,$case,[$finance,$support])['write_snapshot']['narrative']??'');
foreach([
    'Nós não recebemos fisicamente o produto',
    'não comprova que o item foi entregue a nós',
    'prazo de 7 dias',
    'comprovante de entrega',
    'endereço de entrega',
] as $needle){
    srnrAssert(mb_stripos($narrative,$needle,0,'UTF-8')!==false,'Rebuttal must state: '.$needle);
}
srnrAssert(!str_contains($narrative,'SUPPORT_RETURN_NOT_RECEIVED_REBUTTAL'),'Internal reason code must not leak externally.');

echo "seller-support-return-not-received-rebuttal-test: OK\n";
