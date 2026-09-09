<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/GmailParser.php';
require_once __DIR__.'/../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__.'/../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function fbaSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}
function fbaTrue(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$message=[
    'message_id'=>'fba-ship-real-format',
    'thread_id'=>'fba-ship-real-format',
    'from'=>'Notificacoes do Seller Central da Amazon <donotreply@amazon.com>',
    'subject'=>'A Amazon enviou os itens vendidos',
    'received_at'=>'2026-08-27T12:08:23Z',
    'body_text'=>"Ola,\nProcessamos e enviamos seus pedidos do programa FBA - Logistica da Amazon para clientes.\n\n"
        ."Os seguintes itens do pedido completo foram enviados por Amazon.com.br para:\n\nNumero do pedido: 702-6151202-0551403\n\nQuantidade\nItem do pedido\nEnviado por\n\n1\nAssento\n\nAmazon Logistics BR\n\nRastreamento: TBR420574732\n\n"
        ."Os seguintes itens do pedido completo foram enviados por Amazon.com.br para:\n\nNumero do pedido: 701-0630116-9129834\n\nQuantidade\nItem do pedido\nEnviado por\n\n2\nKit Rodinhas\n\nAmazon Logistics BR\n\nRastreamento: TBR420573721\n\nObserve que a entrega durante o fim de semana nao esta disponivel.",
];

$parser=new SvAmazonGmailParser();
$events=$parser->parse($message);
fbaSame(2,count($events),'One Amazon FBA summary email must emit one event per order.');
$byOrder=[];
foreach($events as $event)$byOrder[$event['order_id']??'']=$event;
fbaTrue(isset($byOrder['701-0630116-9129834']),'Target refunded order must not be lost behind the first order in the email.');
$shipment=$byOrder['701-0630116-9129834'];
fbaSame('FBA_SHIPMENT_EMAIL',$shipment['event_type']??null,'FBA shipment event type.');
fbaSame('FBA',$shipment['program']??null,'Official FBA shipment email is authoritative for fulfillment program.');
fbaSame('TBR420573721',$shipment['tracking_id']??null,'Tracking ID must be associated with the matching order.');
fbaSame('Amazon Logistics BR',$shipment['carrier']??null,'Carrier must be associated with the matching order.');
fbaSame(false,$shipment['customer_delivery_confirmed']??false,'Shipment email must never be promoted to delivered evidence.');
fbaTrue(($events[0]['idempotency_key']??'')!==($events[1]['idempotency_key']??''),'Orders in the same email need independent idempotency keys.');

$patch=SvAmazonGmailEventSink::casePatch($shipment,['program'=>'UNKNOWN','state'=>'POLICY_REVIEW_REQUIRED']);
fbaSame(['program'=>'FBA'],$patch,'FBA shipment metadata must classify the program without changing workflow state.');

$case=['id'=>1,'amazon_order_id'=>'701-0630116-9129834','amazon_order_item_id'=>'UNRESOLVED_EMAIL','marketplace_id'=>'A2Q3Y263D00KWC','program'=>'UNKNOWN','refund_initiator'=>'UNKNOWN','physical_status'=>'NOT_RECEIVED','state'=>'POLICY_REVIEW_REQUIRED','safe_t_id'=>null,'quantity_ordered'=>2];
$timeline=[
    ['id'=>1,'case_id'=>1,'event_type'=>'FBA_SHIPMENT_EMAIL','source'=>'GMAIL','occurred_at'=>'2026-08-27 12:08:23','payload'=>['program'=>'FBA','customer_tracking_ids'=>['TBR420573721'],'customer_delivery_carriers'=>['Amazon Logistics BR']]],
    ['id'=>2,'case_id'=>1,'event_type'=>'REFUND_ISSUED_EMAIL','source'=>'GMAIL','occurred_at'=>'2026-09-02 01:50:37','payload'=>['refund_at'=>'2026-09-02 01:50:37','refund_amount'=>'85.50','financial_truth'=>false]],
];
$projected=SvAmazonReturnProjector::projectFrom($case,$timeline);
fbaSame('FBA',$projected['program']??null,'Projection replay must preserve FBA classification.');
fbaSame(['TBR420573721'],$projected['customer_tracking_ids']??null,'Projection replay must preserve shipment tracking as non-delivery evidence.');
fbaSame(['Amazon Logistics BR'],$projected['customer_delivery_carriers']??null,'Projection replay must preserve shipment carrier.');
fbaSame(false,$projected['customer_delivery_confirmed']??null,'Tracking alone must not assert customer delivery.');
$decision=(new SvAmazonSafeTDecisionEngine())->nextAction($projected,$timeline,[],new DateTimeImmutable('2026-09-09T11:00:00Z'));
fbaSame('CHECK_FINANCES',$decision['action']??null,'Known classic FBA refund must use the automatic FBA recovery path instead of REFUND_INITIATOR_UNKNOWN human review.');
fbaSame('CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE',$decision['reason']??null,'Automatic FBA route must remain auditable.');

$now=new DateTimeImmutable('2026-09-09T11:00:00Z');
$state=[];
foreach(SvAmazonReturnsRuntime::cadences() as $task=>$_seconds)$state[$task]=$now->format(DATE_ATOM);
$state['decision_stack_revision']=SvAmazonReturnsRuntime::decisionStackRevision();
$state['gmail_ingestion_revision']='old';
fbaTrue(method_exists(SvAmazonReturnsRuntime::class,'gmailIngestionRevision'),'Runtime needs a Gmail ingestion revision fingerprint.');
$gmailRevision=SvAmazonReturnsRuntime::gmailIngestionRevision();
$due=SvAmazonReturnsRuntime::dueTasks($state,$now,$state['decision_stack_revision'],$gmailRevision);
fbaTrue(in_array('gmail_refund_reconciliation',$due,true),'A Gmail parser/sink revision must force historical evidence reconciliation once after deploy.');
$ordered=SvAmazonReturnsRuntime::decisionSafeOrder($due);
fbaTrue(array_search('gmail_refund_reconciliation',$ordered,true)<array_search('scheduler',$ordered,true),'Historical Gmail evidence must be ingested before re-evaluating decisions.');

$state['gmail_ingestion_revision']=$gmailRevision;
$due=SvAmazonReturnsRuntime::dueTasks($state,$now,$state['decision_stack_revision'],$gmailRevision);
fbaTrue(!in_array('gmail_refund_reconciliation',$due,true),'Unchanged ingestion code must not create an extra reconciliation cycle.');

echo "fba-shipment-gmail-ingestion-test: OK\n";
