<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnGateway.php';
function bgSame(mixed $e,mixed $a,string $m): void { if($e!==$a)throw new RuntimeException($m.' expected '.var_export($e,true).' got '.var_export($a,true)); }
$calls=[];
$runner=static function(array $payload) use (&$calls): array {
    $calls[]=$payload;
    if(($payload['action']??'')==='CREATE')return ['status'=>'ACCEPTED','submitted'=>true,'external_id'=>'991','retry_safe'=>true];
    if(($payload['action']??'')==='VALIDATE_CREATE')return ['status'=>'READY','submitted'=>false,'external_id'=>null,'retry_safe'=>true];
    if(($payload['action']??'')==='READBACK')return ['status'=>'FOUND','submitted'=>false,'external_id'=>'991','record'=>['id'=>991,'idNotaFiscal'=>202]];
    if(($payload['action']??'')==='PREFLIGHT')return ['status'=>'FOUND','submitted'=>false,'external_id'=>'880','retry_safe'=>true];
    throw new RuntimeException('unexpected action');
};
$gateway=new SvAmazonOlistBrowserErpSalesReturnGateway($runner,'http://127.0.0.1:9226');
$result=$gateway->create([
    'amazon_order_id'=>'702-1234567-1234567','refund_at'=>'2026-09-12 10:00:00',
    'original_sale'=>['invoice_id'=>'202','invoice_number'=>'303'],
    'items'=>[['sku'=>'SKU-1','quantity_refunded'=>1]],
]);
bgSame(true,$result['ok']??null,'Accepted browser create must map to gateway success.');
bgSame('991',$result['id']??null,'Gateway must preserve ERP return ID.');
bgSame('202',$calls[0]['original_invoice_id']??null,'Gateway must flatten original invoice ID for adapter.');
bgSame('303',$calls[0]['original_invoice_number']??null,'Gateway must flatten original invoice number for adapter.');
bgSame('2026-09-12',$calls[0]['refund_at']??null,'Gateway must send the refund date only.');
$ready=$gateway->preflightCreate(['amazon_order_id'=>'702-1234567-1234567','refund_at'=>'2026-09-12 10:00:00','original_sale'=>['invoice_id'=>'202','invoice_number'=>'303'],'items'=>[['sku'=>'SKU-1','quantity_refunded'=>1]]]);
bgSame(true,$ready,'Gateway create preflight must confirm a write-safe candidate without writing.');
$existing=$gateway->probeExisting('202','303');
bgSame('880',$existing,'Gateway preflight must expose an existing internal ERP return without writing.');
$read=$gateway->readBack('991','702-1234567-1234567');
bgSame(991,$read['id']??null,'Gateway read-back must return the ERP record.');
$missingGateway=new SvAmazonOlistBrowserErpSalesReturnGateway(
    static fn(array $p): array=>['status'=>'NOT_FOUND','submitted'=>false,'external_id'=>'991','record'=>null]
);
bgSame(null,$missingGateway->readBack('991','702-1234567-1234567'),'Confirmed target absence must remain a null read-back.');

$authReadGateway=new SvAmazonOlistBrowserErpSalesReturnGateway(
    static fn(array $p): array=>['status'=>'AUTH_REQUIRED','submitted'=>false,'external_id'=>null,'retry_safe'=>true]
);
$authReadFailedClosed=false;
try{
    $authReadGateway->readBack('991','702-1234567-1234567');
}catch(RuntimeException $e){
    $authReadFailedClosed=str_contains($e->getMessage(),'AUTH_REQUIRED');
}
bgSame(true,$authReadFailedClosed,'Authentication failure must never be collapsed into a false NOT_FOUND read-back.');
$authGateway=new SvAmazonOlistBrowserErpSalesReturnGateway(static fn(array $p): array=>['status'=>'AUTH_REQUIRED','submitted'=>false,'external_id'=>null,'retry_safe'=>true]);
$blocked=$authGateway->create(['amazon_order_id'=>'702-1234567-1234567','refund_at'=>'2026-09-12 10:00:00','original_sale'=>['invoice_id'=>'202'],'items'=>[['sku'=>'SKU-1','quantity_refunded'=>1]]]);
bgSame(false,$blocked['ok']??null,'Authentication failure must not be treated as success.');
bgSame('ERP_SALES_RETURN_AUTH_REQUIRED',$blocked['error_code']??null,'Authentication failure must expose a safe gateway code.');

$itemGateway=new SvAmazonOlistBrowserErpSalesReturnGateway(static fn(array $p): array=>['status'=>'ITEM_MAPPING_FAILED','submitted'=>false,'external_id'=>null,'retry_safe'=>true]);
$itemBlocked=$itemGateway->create(['amazon_order_id'=>'702-1234567-1234567','refund_at'=>'2026-09-12 10:00:00','original_sale'=>['invoice_id'=>'202'],'items'=>[['sku'=>'SKU-X','quantity_refunded'=>1]]]);
bgSame('ERP_SALES_RETURN_ITEM_MAPPING_FAILED',$itemBlocked['error_code']??null,'Ambiguous item mapping must persist as a non-generic blocker.');

$addressGateway=new SvAmazonOlistBrowserErpSalesReturnGateway(static fn(array $p): array=>['status'=>'ADDRESS_NUMBER_REQUIRED','submitted'=>false,'external_id'=>null,'retry_safe'=>true]);
$addressBlocked=$addressGateway->create(['amazon_order_id'=>'702-1234567-1234567','refund_at'=>'2026-09-12 10:00:00','original_sale'=>['invoice_id'=>'202'],'items'=>[['sku'=>'SKU-1','quantity_refunded'=>1]]]);
bgSame('ERP_SALES_RETURN_ADDRESS_NUMBER_REQUIRED',$addressBlocked['error_code']??null,'Missing customer address number must persist as a specific non-generic blocker.');

echo "erp-sales-return-browser-gateway-test: OK\n";
