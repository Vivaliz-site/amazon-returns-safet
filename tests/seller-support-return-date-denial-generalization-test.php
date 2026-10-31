<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';

function srdSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function srdAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$case=[
    'id'=>404,
    'amazon_order_id'=>'701-9999999-9999999',
    'program'=>'FBA',
    'safe_t_id'=>'99999-99999-9999999',
    'support_case_id'=>'29999999999',
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
    'id'=>1,'case_id'=>404,'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED','source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-30 15:00:00',
    'payload'=>['refresh_complete'=>true,'outstanding_amount'=>'22.80','ambiguous_reimbursement_transactions'=>0,'unsettled_financial_evidence'=>false],
];

$support=[
    'id'=>2,'case_id'=>404,'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-30 15:01:00',
    'payload'=>[
        'case_id'=>'29999999999',
        'case_status'=>'RESOLVED',
        'latest_text'=>'Após investigação detalhada, foi identificado que o item foi devolvido em 11/07/2026, e o reembolso ao comprador ocorreu em 01/07/2026. A reivindicação SAFE-T foi registrada em 10/08/2026, fora do período elegível de 7 dias contados a partir da data de devolução. Por esse motivo, a reivindicação não pôde ser aprovada.',
    ],
];

srdSame(
    'RETURN_NOT_RECEIVED_DISPUTE',
    SvAmazonSellerSupportStatus::resolution($support['payload']),
    'A seven-day return-date denial must be treated as a seller nonreceipt dispute when case state confirms NOT_RECEIVED, even if the latest Amazon reply omits the earlier nonreceipt wording.'
);

$engine=new SvAmazonSafeTDecisionEngine();
$decision=$engine->nextAction($case,[$finance,$support],['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-09-30T15:05:00Z'));

srdSame('SELLER_SUPPORT_UPDATE',$decision['action']??null,'The denial must be answered automatically in Seller Support.');
srdSame('SUPPORT_RETURN_NOT_RECEIVED_REBUTTAL',$decision['reason']??null,'All equivalent return-date/7-day denials must use the dedicated nonreceipt rebuttal.');
srdSame('29999999999',$decision['support_case_id']??null,'The rebuttal must target the existing Seller Support case.');

$narrative=(string)(SvAmazonExternalWritePayload::build($decision,$case,[$finance,$support])['write_snapshot']['narrative']??'');
foreach(['Nós não recebemos fisicamente o produto','prazo de 7 dias','comprovante de entrega','endereço de entrega'] as $needle){
    srdAssert(mb_stripos($narrative,$needle,0,'UTF-8')!==false,'Generalized rebuttal must state: '.$needle);
}

echo "seller-support-return-date-denial-generalization-test: OK\n";
