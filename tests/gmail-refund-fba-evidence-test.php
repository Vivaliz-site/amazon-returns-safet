<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/GmailParser.php';
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';

function gpfSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.json_encode($expected).' actual='.json_encode($actual));
}
function gpfTrue(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$parser=new SvAmazonGmailParser();
$message=[
    'message_id'=>'1a05fcfa99eb924f',
    'thread_id'=>'1a05fcfa99eb924f',
    'from'=>'Comunicações do Amazon Seller Central <donotreply@amazon.com>',
    'subject'=>'Reembolso de 85.5 BRL iniciado para o pedido 701-0630116-9129834',
    'received_at'=>'2026-09-02T01:50:37Z',
    'body_text'=>"Prezado(a) ShopVivaLiz,\n\nIniciou um reembolso no valor de BRL 85.5.\n\nPedido : 701-0630116-9129834\nLogística: Enviado pela Amazon\n\nEmissor do reembolso: Customer Service\n\nASIN\nSKU\nQuantidade do pedido\nQuantidade da devolução\nProduto do pedido\nMotivo para reembolso\nB0CLVRBLZL\nBC-RTCH-B2G7\n2\n2\nKit 4 Rodinhas\nPedido não recebido\n\nSua conta de vendedor será debitada adequadamente.",
];
$event=$parser->parse($message)[0]??[];
gpfSame('FBA',$event['program']??null,'Explicit Enviado pela Amazon logistics must classify refund evidence as FBA.');
gpfSame('AMAZON_CUSTOMER_SERVICE',$event['refund_initiator']??null,'Explicit Customer Service issuer must remain classified.');

$patch=SvAmazonGmailEventSink::casePatch($event,[
    'program'=>'UNKNOWN','refund_initiator'=>'UNKNOWN','refund_amount'=>'0.00','refund_at'=>null,
]);
gpfSame('FBA',$patch['program']??null,'Refund email must enrich an UNKNOWN case program from explicit FBA evidence.');
gpfSame('85.50',$patch['refund_amount']??null,'Observed refund amount must replace an unresolved zero placeholder.');

if(!method_exists(SvAmazonGmailEventSink::class,'programEvidence')){
    throw new RuntimeException('Re-ingesting an old Gmail refund must create durable program evidence.');
}
$programMethod=new ReflectionMethod(SvAmazonGmailEventSink::class,'programEvidence');
$programEvidence=$programMethod->invoke(null,1,$event,'701-0630116-9129834','2026-09-02 01:50:37','1a05fcfa99eb924f');
gpfSame('PROGRAM_CONFIRMED',$programEvidence['event_type']??null,'Explicit FBA logistics must persist as durable program evidence.');
gpfSame('FBA',$programEvidence['payload']['program']??null,'Durable program evidence must carry FBA.');

$case=[
    'id'=>1,'amazon_order_id'=>'701-0630116-9129834','quantity_ordered'=>2,
    'quantity_refunded'=>0,'quantity_received'=>0,'program'=>'UNKNOWN',
    'refund_initiator'=>'UNKNOWN','physical_status'=>'NOT_RECEIVED','state'=>'POLICY_REVIEW_REQUIRED',
    'expected_reimbursement_amount'=>'0.00','reconciled_credit_amount'=>'0.00','safe_t_id'=>null,
];
$timeline=[
    ['id'=>1,'case_id'=>1,'event_type'=>'REFUND_ISSUED_EMAIL','source'=>'GMAIL','occurred_at'=>'2026-09-02 01:50:37','payload'=>['refund_at'=>'2026-09-02 01:50:37','refund_amount'=>'85.50','financial_truth'=>false]],
    ['id'=>2,'case_id'=>1,'event_type'=>'REFUND_INITIATOR_CONFIRMED','source'=>'GMAIL','occurred_at'=>'2026-09-02 01:50:37','payload'=>['refund_initiator'=>'AMAZON_CUSTOMER_SERVICE','financial_truth'=>false]],
    $programEvidence+['id'=>3],
];
$projected=SvAmazonReturnProjector::projectFrom($case,$timeline);
gpfSame('FBA',$projected['program']??null,'Projection replay must preserve durable FBA evidence.');
$decision=(new SvAmazonSafeTDecisionEngine())->nextAction($projected,$timeline,['eligible'=>false,'state'=>'POLICY_REVIEW_REQUIRED'],new DateTimeImmutable('2026-09-09T15:45:00Z'));
gpfSame('CHECK_FINANCES',$decision['action']??null,'Explicit FBA refund must use automatic finance recovery instead of human review.');
gpfSame('CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE',$decision['reason']??null,'Automatic FBA route must remain auditable.');

$seller=$message;
$seller['message_id']='seller-fulfilled';
$seller['subject']='Reembolso de 77.98 BRL iniciado para o pedido 701-4236758-7416240';
$seller['body_text']="Pedido: 701-4236758-7416240\nRede logística da Amazon: Enviado pelo vendedor\nMotivo para reembolso\nDevolução do cliente";
$sellerEvent=$parser->parse($seller)[0]??[];
gpfTrue(($sellerEvent['program']??null)!=='FBA','Seller-fulfilled wording must never be classified as FBA.');

echo "gmail-refund-fba-evidence-test: OK\n";
