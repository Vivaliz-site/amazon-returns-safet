<?php
declare(strict_types=1);

function erpReturnAssert(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
function erpReturnSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual){
        throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
    }
}

$path=__DIR__.'/../includes/amazon-returns/ErpReturnInvoiceLookup.php';
erpReturnAssert(is_file($path),'ERP return invoice lookup implementation is missing.');
require_once $path;

$calls=[];
$http=static function(string $method,string $url,array $headers,?string $body) use (&$calls): array {
    $calls[]=compact('method','url','headers','body');
    $path=(string)parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/notas')){
        return ['status'=>200,'json'=>['itens'=>[
            ['id'=>901,'numero'=>'301','serie'=>'1','tipo'=>'E','situacao'=>'6','chaveAcesso'=>'RETKEY301','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
            ['id'=>902,'numero'=>'302','serie'=>'1','tipo'=>'E','situacao'=>'6','chaveAcesso'=>'NORMAL302','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
            ['id'=>903,'numero'=>'303','serie'=>'1','tipo'=>'E','situacao'=>'6','chaveAcesso'=>'OTHER303','ecommerce'=>['numeroPedidoEcommerce'=>'702-9999999-8888888']],
        ]]];
    }
    $id=basename($path);
    $details=[
        '901'=>['id'=>901,'numero'=>'301','serie'=>'1','tipo'=>'E','situacao'=>'6','finalidade'=>4,'chaveAcesso'=>'RETKEY301','dataEmissao'=>'2026-09-10T12:00:00-03:00','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
        '902'=>['id'=>902,'numero'=>'302','serie'=>'1','tipo'=>'E','situacao'=>'6','finalidade'=>1,'chaveAcesso'=>'NORMAL302','dataEmissao'=>'2026-09-10T12:05:00-03:00','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
        '903'=>['id'=>903,'numero'=>'303','serie'=>'1','tipo'=>'E','situacao'=>'6','finalidade'=>4,'chaveAcesso'=>'OTHER303','dataEmissao'=>'2026-09-10T12:10:00-03:00','ecommerce'=>['numeroPedidoEcommerce'=>'702-9999999-8888888']],
    ];
    return ['status'=>200,'json'=>$details[$id]??[]];
};

$lookup=new SvAmazonErpReturnInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$http);
$found=$lookup->findForOrder('702-1111111-2222222');
erpReturnSame('901',$found['invoice_id']??null,'Lookup must return the qualifying return NF only.');
erpReturnSame('301',$found['invoice_number']??null,'Return NF number must be preserved.');
erpReturnSame('RETKEY301',$found['access_key']??null,'Return NF access key must be preserved.');
erpReturnSame(4,$found['purpose']??null,'Return purpose must be preserved.');
erpReturnSame('702-1111111-2222222',$found['order_id']??null,'Return NF must remain linked to the exact Amazon order.');

$query=[];
parse_str((string)parse_url((string)($calls[0]['url']??''),PHP_URL_QUERY),$query);
erpReturnSame('E',$query['tipo']??null,'Return lookup must only query entry invoices.');
erpReturnSame('GET',$calls[0]['method']??null,'Return lookup must be read-only.');

$ambiguousHttp=static function(string $method,string $url,array $headers,?string $body): array {
    $path=(string)parse_url($url,PHP_URL_PATH);
    if(str_ends_with($path,'/notas'))return ['status'=>200,'json'=>['itens'=>[
        ['id'=>910,'numero'=>'310','tipo'=>'E','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
        ['id'=>911,'numero'=>'311','tipo'=>'E','ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']],
    ]]];
    $id=(int)basename($path);
    return ['status'=>200,'json'=>['id'=>$id,'numero'=>(string)($id-600),'tipo'=>'E','finalidade'=>4,'ecommerce'=>['numeroPedidoEcommerce'=>'702-1111111-2222222']]];
};

$ambiguous=false;
try{
    (new SvAmazonErpReturnInvoiceLookup(['TINY_ACCESS_TOKEN'=>'test-token'],$ambiguousHttp))->findForOrder('702-1111111-2222222');
}catch(UnexpectedValueException){
    $ambiguous=true;
}
erpReturnAssert($ambiguous,'Multiple qualifying return NFs must block creation instead of guessing.');

echo "erp-return-invoice-dedupe-test: OK\n";
