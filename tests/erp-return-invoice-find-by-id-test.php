<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpReturnInvoiceLookup.php';
function fbiSame(mixed $e,mixed $a,string $m): void { if($e!==$a){fwrite(STDERR,"FAIL: $m\n");exit(1);} }
$calls=[];
$mk=function(array $response) use (&$calls): SvAmazonErpReturnInvoiceLookup {
    $http=function(string $method,string $url,array $h,?string $b) use (&$calls,$response): array { $calls[]=[$method,$url]; return $response; };
    return new SvAmazonErpReturnInvoiceLookup(['TINY_ACCESS_TOKEN'=>'t'],$http);
};
$ok=['status'=>200,'json'=>['id'=>901,'tipo'=>'E','finalidade'=>4,'numero'=>'301','serie'=>'1','chaveAcesso'=>'RETKEY301','situacao'=>7,'dataEmissao'=>'2026-09-12T13:00:00-03:00']];
$r=$mk($ok)->findById('901');
fbiSame('301',$r['invoice_number']??null,'number');fbiSame('RETKEY301',$r['access_key']??null,'key');fbiSame('7',$r['status']??null,'status');fbiSame('2026-09-12T13:00:00-03:00',$r['issued_at']??null,'issued_at');fbiSame('901',$r['invoice_id']??null,'id');
fbiSame(['GET','https://api.tiny.com.br/public-api/v3/notas/901'],$calls[0]??null,'read-only GET by id');
fbiSame(null,$mk(['status'=>404,'json'=>[]])->findById('901'),'404 is null');
fbiSame(null,$mk(['status'=>200,'json'=>['id'=>901,'tipo'=>'S','finalidade'=>1]])->findById('901'),'sale NF must not be accepted as return NF');
fbiSame(null,$mk(['status'=>200,'json'=>['id'=>901,'tipo'=>'E','finalidade'=>1]])->findById('901'),'non-return purpose must be rejected');
foreach(['','0','abc','9 01','-1'] as $bad){ try{$mk($ok)->findById($bad);fbiSame(true,false,"invalid id $bad must throw");}catch(InvalidArgumentException){} }
try{$mk(['status'=>500,'json'=>[]])->findById('901');fbiSame(true,false,'HTTP 500 must throw');}catch(RuntimeException){}
echo "erp-return-invoice-find-by-id-test OK\n";
