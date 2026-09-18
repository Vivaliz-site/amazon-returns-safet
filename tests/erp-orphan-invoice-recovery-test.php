<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/ErpInvoiceLookup.php';
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';

function orphanAssert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function orphanSame(mixed $expected,mixed $actual,string $message): void {if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

$calls=[];$paced=0;
$http=static function(string $method,string $url,array $headers,?string $body) use (&$calls): array {
    $calls[]=$url;$path=(string)parse_url($url,PHP_URL_PATH);
    if($path==='/public-api/v3/notas'){
        $query=[];parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
        orphanSame('2026-03-24',$query['dataInicial']??null,'Orphan lookup must use exact order date start.');
        orphanSame('2026-03-24',$query['dataFinal']??null,'Orphan lookup must use exact order date end.');
        orphanSame('S',$query['tipo']??null,'Orphan lookup must restrict outgoing invoices.');
        return ['status'=>200,'json'=>[
            'itens'=>[
                ['id'=>100,'numero'=>'019525','tipo'=>'S','situacao'=>'6','dataEmissao'=>'2026-03-24','valor'=>22.90,'ecommerce'=>['id'=>0,'nome'=>'','numeroPedidoEcommerce'=>'','numeroPedidoCanalVenda'=>'']],
                ['id'=>101,'numero'=>'019526','tipo'=>'S','situacao'=>'6','dataEmissao'=>'2026-03-24','valor'=>22.90,'ecommerce'=>['id'=>0,'nome'=>'','numeroPedidoEcommerce'=>'','numeroPedidoCanalVenda'=>'']],
                ['id'=>102,'numero'=>'019527','tipo'=>'S','situacao'=>'6','dataEmissao'=>'2026-03-24','valor'=>22.90,'ecommerce'=>['id'=>27478,'nome'=>'Amazon Onsite','numeroPedidoEcommerce'=>'701-1111111-2222222']],
                ['id'=>103,'numero'=>'019528','tipo'=>'S','situacao'=>'6','dataEmissao'=>'2026-03-24','valor'=>99.00,'ecommerce'=>['id'=>0]],
            ],
            'paginacao'=>['limit'=>100,'offset'=>0,'total'=>4],
        ]];
    }
    if($path==='/public-api/v3/notas/100')return ['status'=>200,'json'=>[
        'id'=>100,'numero'=>'019525','serie'=>'1','tipo'=>'S','situacao'=>'6','finalidade'=>'1','dataEmissao'=>'2026-03-24','valor'=>22.90,
        'chaveAcesso'=>'31260349903300000170550010000195251253955794','ecommerce'=>['id'=>0],
        'itens'=>[['codigo'=>'Ved-80t','quantidade'=>1,'valorUnitario'=>22.90]],
    ]];
    if($path==='/public-api/v3/notas/101')return ['status'=>200,'json'=>[
        'id'=>101,'numero'=>'019526','serie'=>'1','tipo'=>'S','situacao'=>'6','finalidade'=>'1','dataEmissao'=>'2026-03-24','valor'=>22.90,
        'chaveAcesso'=>'31260349903300000170550010000195261253955795','ecommerce'=>['id'=>0],
        'itens'=>[['codigo'=>'OTHER-SKU','quantidade'=>1,'valorUnitario'=>22.90]],
    ]];
    throw new RuntimeException('Unexpected ERP test URL: '.$url);
};
$lookup=new SvAmazonErpInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$http,null,static function() use (&$paced):void{$paced++;});
$match=$lookup->findOrphanSaleForOrder('702-2046200-4661854','2026-03-24','22.90',['VED-80T'=>1]);
orphanSame('100',$match['invoice_id']??null,'Unique orphan invoice must be recovered.');
orphanSame('019525',$match['invoice_number']??null,'Recovered invoice number must be preserved.');
orphanSame('702-2046200-4661854',$match['order_id']??null,'Recovered invoice must bind only to requested Amazon order.');
orphanSame('ORPHAN_INVOICE_EXACT_DATE_AMOUNT_ITEMS',$match['match_method']??null,'Recovery method must be observable.');
orphanSame(3,$paced,'One list plus two orphan detail requests must each be paced.');
orphanSame(3,count($calls),'Linked ecommerce and wrong-value rows must not spend detail requests.');

$cached=$lookup->findOrphanSaleForOrder('702-9999999-8888888','2026-03-24','22.90',['other-sku'=>1]);
orphanSame('101',$cached['invoice_id']??null,'Cached date data may recover another exact SKU.');
orphanSame(3,$paced,'Same-date list and invoice details must be cached within a cycle.');

$ambiguousHttp=static function(string $method,string $url,array $headers,?string $body): array {
    $path=(string)parse_url($url,PHP_URL_PATH);
    if($path==='/public-api/v3/notas')return ['status'=>200,'json'=>['itens'=>[
        ['id'=>201,'tipo'=>'S','situacao'=>'6','dataEmissao'=>'2026-04-04','valor'=>25.89,'ecommerce'=>['id'=>0]],
        ['id'=>202,'tipo'=>'S','situacao'=>'6','dataEmissao'=>'2026-04-04','valor'=>25.89,'ecommerce'=>['id'=>0]],
    ],'paginacao'=>['total'=>2]]];
    if(in_array($path,['/public-api/v3/notas/201','/public-api/v3/notas/202'],true)){
        $id=str_ends_with($path,'201')?201:202;
        return ['status'=>200,'json'=>['id'=>$id,'numero'=>(string)$id,'tipo'=>'S','situacao'=>'6','finalidade'=>'1','dataEmissao'=>'2026-04-04','valor'=>25.89,'ecommerce'=>['id'=>0],'itens'=>[['codigo'=>'FO-HKY0-X2RX','quantidade'=>1]]]];
    }
    throw new RuntimeException('Unexpected ambiguous URL.');
};
$ambiguous=(new SvAmazonErpInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$ambiguousHttp))->findOrphanSaleForOrder(
    '702-9151773-6072237','2026-04-04','25.89',['FO-HKY0-X2RX'=>1]
);
orphanSame(null,$ambiguous,'Multiple exact orphan invoices must never auto-link.');

$invalidHttp=static function(string $method,string $url,array $headers,?string $body): array {
    $path=(string)parse_url($url,PHP_URL_PATH);
    if($path==='/public-api/v3/notas')return ['status'=>200,'json'=>['itens'=>[
        ['id'=>301,'tipo'=>'S','situacao'=>'6','dataEmissao'=>'2026-04-04','valor'=>22.90,'ecommerce'=>['id'=>0]],
    ],'paginacao'=>['total'=>1]]];
    return ['status'=>200,'json'=>['id'=>301,'numero'=>'301','tipo'=>'S','situacao'=>'6','finalidade'=>'4','dataEmissao'=>'2026-04-04','valor'=>22.90,'ecommerce'=>['id'=>0],'itens'=>[['codigo'=>'I7-LTFG-UH4X','quantidade'=>1]]]];
};
$invalid=(new SvAmazonErpInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$invalidHttp))->findOrphanSaleForOrder(
    '702-2413783-2626644','2026-04-04','22.90',['I7-LTFG-UH4X'=>1]
);
orphanSame(null,$invalid,'Return/non-normal invoice must never be used as original sale.');

$cases=[
    ['id'=>11,'sku'=>'Ved-80t','quantity_ordered'=>1],
    ['id'=>12,'sku'=>'ABC','quantity_ordered'=>2],
];
$events=[
  11=>[
    ['event_type'=>'ORDER_SYNCED','payload'=>['order_at'=>'2026-03-24 18:37:09']],
    ['event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','payload'=>['transaction'=>['transaction_type'=>'Shipment','breakdowns'=>[['breakdown_type'=>'Sales','breakdown_amount'=>['amount'=>'45.80','currency'=>'BRL']]]]]],
  ],
  12=>[
    ['event_type'=>'ORDER_SYNCED','payload'=>['order_at'=>'2026-03-24 18:37:09']],
    ['event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','payload'=>['transaction'=>['transaction_type'=>'Shipment','breakdowns'=>[['breakdown_type'=>'Sales','breakdown_amount'=>['amount'=>'45.80','currency'=>'BRL']]]]]],
  ],
];
$facts=SvAmazonErpSalesReturnTask::orphanSaleFacts($cases,static fn(int $id):array=>$events[$id]??[]);
orphanSame('2026-03-24',$facts['order_date']??null,'Local facts must preserve unique order date.');
$utcBoundaryEvents=$events;
$utcBoundaryEvents[11][0]=['event_type'=>'ORDER_SYNCED','payload'=>['order_at'=>'2026-04-02T00:30:00Z']];
$utcBoundaryEvents[12][0]=['event_type'=>'ORDER_SYNCED','payload'=>['order_at'=>'2026-04-02T00:30:00Z']];
$boundaryFacts=SvAmazonErpSalesReturnTask::orphanSaleFacts($cases,static fn(int $id):array=>$utcBoundaryEvents[$id]??[]);
orphanSame('2026-04-01',$boundaryFacts['order_date']??null,'ERP orphan matching must use the Brazil-local sale date, not the UTC calendar date.');
orphanSame('45.80',$facts['sales_amount']??null,'Duplicate shipment observations must collapse to one distinct Sales amount.');
orphanSame(['abc'=>2,'ved-80t'=>1],$facts['items']??null,'Case SKU quantities must form a normalized exact multiset.');

$events[12][]=['event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','payload'=>['transaction'=>['transaction_type'=>'Shipment','breakdowns'=>[['breakdown_type'=>'Sales','breakdown_amount'=>['amount'=>'46.00','currency'=>'BRL']]]]]];
orphanSame(null,SvAmazonErpSalesReturnTask::orphanSaleFacts($cases,static fn(int $id):array=>$events[$id]??[]),'Conflicting Sales amounts must disable orphan recovery.');
$cases[1]['sku']='';
orphanSame(null,SvAmazonErpSalesReturnTask::orphanSaleFacts($cases,static fn(int $id):array=>$events[$id]??[]),'Missing SKU must disable orphan recovery.');

$threw=false;
try{$lookup->findOrphanSaleForOrder('702-2046200-4661854','2026-02-31','22.90',['VED-80T'=>1]);}catch(InvalidArgumentException){$threw=true;}
orphanAssert($threw,'Invalid order date must fail closed.');

echo "erp-orphan-invoice-recovery-test: OK\n";
