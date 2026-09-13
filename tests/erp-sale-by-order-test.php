<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/ErpInvoiceLookup.php';

function erpSaleAssert(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
function erpSaleSame(mixed $expected,mixed $actual,string $message): void {if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}

$calls=[];
$http=static function(string $method,string $url,array $headers,?string $body) use (&$calls): array {
    $calls[]=compact('method','url','headers','body');
    return ['status'=>200,'json'=>['itens'=>[
        ['id'=>500,'numero'=>'1001','serie'=>'1','tipo'=>'S','situacao'=>'6','chaveAcesso'=>'SALEKEY1001','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222','nome'=>'Amazon']],
        ['id'=>501,'numero'=>'1002','serie'=>'1','tipo'=>'S','situacao'=>'6','chaveAcesso'=>'SALEKEY1002','ecommerce'=>['numeroPedidoEcommerce'=>'702-9999999-8888888','nome'=>'Amazon']],
    ]]];
};
$lookup=new SvAmazonErpInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$http);
erpSaleAssert(method_exists($lookup,'findSaleForOrder'),'ERP invoice lookup must support lookup by Amazon order.');
$found=$lookup->findSaleForOrder('702-1111111-2222222');
erpSaleSame('500',$found['invoice_id']??null,'Sale lookup must preserve invoice ID.');
erpSaleSame('1001',$found['invoice_number']??null,'Sale lookup must preserve invoice number.');
erpSaleSame('SALEKEY1001',$found['access_key']??null,'Sale lookup must preserve access key.');
erpSaleSame('702-1111111-2222222',$found['order_id']??null,'Sale lookup must preserve exact Amazon order.');
$query=[];parse_str((string)parse_url((string)($calls[0]['url']??''),PHP_URL_QUERY),$query);
erpSaleSame('S',$query['tipo']??null,'Sale lookup must query outgoing invoices only.');
erpSaleSame('702-1111111-2222222',$query['numeroPedidoEcommerce']??null,'Sale lookup must use exact Amazon order filter.');
erpSaleSame('GET',$calls[0]['method']??null,'Sale lookup must remain read-only.');

$ambiguousHttp=static function(string $method,string $url,array $headers,?string $body): array {
    return ['status'=>200,'json'=>['itens'=>[
        ['id'=>510,'numero'=>'1010','tipo'=>'S','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
        ['id'=>511,'numero'=>'1011','tipo'=>'S','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
    ]]];
};
$ambiguous=false;
try{(new SvAmazonErpInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$ambiguousHttp))->findSaleForOrder('702-1111111-2222222');}
catch(UnexpectedValueException){$ambiguous=true;}
erpSaleAssert($ambiguous,'Multiple sale invoices for the same Amazon order must block automatic matching.');

echo "erp-sale-by-order-test: OK\n";
