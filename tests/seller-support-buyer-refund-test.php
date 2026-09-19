<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/ExternalWritePayload.php';

function brsrSame(mixed $expected,mixed $actual,string $message):void
{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function brsrAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$case=[
    'id'=>22144771981,
    'amazon_order_id'=>'702-1808735-0608208',
    'program'=>'FBA',
    'safe_t_id'=>null,
    'support_case_id'=>'22144771981',
    'support_case_status'=>'RESOLVED',
    'state'=>'CREDIT_PENDING',
    'physical_status'=>'NOT_RECEIVED',
    'refund_at'=>'2026-09-01 12:00:00',
    'seller_debit_at'=>'2026-09-01 12:00:00',
    'refund_initiator'=>'AMAZON_CUSTOMER_SERVICE',
    'expected_reimbursement_amount'=>'68.57',
    'reconciled_credit_amount'=>'0.00',
];
$finance=[
    'id'=>1,
    'case_id'=>22144771981,
    'event_type'=>'FINANCIAL_RECONCILIATION_CHECKED',
    'source'=>'SP_API_FINANCES',
    'occurred_at'=>'2026-09-19 18:25:00',
    'payload'=>[
        'refresh_complete'=>true,
        'credit_amount'=>'0.00',
        'outstanding_amount'=>'68.57',
        'ambiguous_reimbursement_transactions'=>0,
        'unsettled_financial_evidence'=>false,
    ],
];
$support=[
    'id'=>2,
    'case_id'=>22144771981,
    'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED',
    'source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-19 18:33:42',
    'payload'=>[
        'case_id'=>'22144771981',
        'case_status'=>'RESOLVED',
        'latest_text'=>'Entendemos que ao verificar a conciliação financeira da sua conta, você identificou um saldo pendente de R$ 68,57 sem nenhum crédito registrado até o momento. Após consultar o relatório de pagamentos na sua conta Seller Central, confirmamos que o valor referente a esse pedido já foi reembolsado diretamente ao comprador. Isso significa que a transação de devolução foi processada e o cliente final já recebeu o estorno correspondente. Recomendamos consultar periodicamente seus relatórios de pagamentos para ter visibilidade sobre reembolsos, créditos e eventuais ressarcimentos.',
    ],
];

$engine=new SvAmazonSafeTDecisionEngine();
$decision=$engine->nextAction(
    $case,
    [$finance,$support],
    ['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],
    new DateTimeImmutable('2026-09-19T18:40:00Z')
);

brsrSame('SELLER_SUPPORT_UPDATE',$decision['action']??null,'Buyer refund evidence must trigger a reply in the existing Seller Support case while seller credit is still unpaid.');
brsrSame('SUPPORT_BUYER_REFUND_IS_NOT_SELLER_REIMBURSEMENT',$decision['reason']??null,'Buyer refund and seller reimbursement must be distinguished explicitly.');
brsrSame('22144771981',$decision['support_case_id']??null,'The app must reply in the same Seller Support case.');
brsrAssert(preg_match('/^[a-f0-9]{64}$/',(string)($decision['idempotency_key']??''))===1,'Seller Support clarification must be idempotent.');

$snapshot=SvAmazonExternalWritePayload::build($decision,$case,[$finance,$support])['write_snapshot']??[];
$narrative=(string)($snapshot['narrative']??'');
foreach([
    'Temos ciência de que o comprador já foi reembolsado',
    'nossa solicitação não se refere ao reembolso realizado ao comprador',
    'ressarcimento devido à ShopVivaLiz, na condição de vendedor',
    'R$ 68,57',
    'data do crédito',
    'ID da transação financeira',
    'ID do ressarcimento',
    'nossa conta de vendedor',
] as $needle){
    brsrAssert(mb_stripos($narrative,$needle,0,'UTF-8')!==false,'Seller Support reply must state: '.$needle);
}
brsrAssert(!str_contains($narrative,'SUPPORT_BUYER_REFUND_IS_NOT_SELLER_REIMBURSEMENT'),'Internal reason codes must never leak into the external Seller Support message.');

echo "seller-support-buyer-refund-test: OK\n";
