<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnCanary.php';
function ecSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
$workflow=['amazon_order_id'=>'701-1234567-1234567','status'=>'READY_TO_CREATE','original_invoice_id'=>'9001'];
$case=['id'=>77,'amazon_order_id'=>'701-1234567-1234567','sku'=>'SKU-1','quantity_refunded'=>1,'refund_at'=>'2026-09-15 10:00:00'];
$sale=['order_id'=>'701-1234567-1234567','invoice_id'=>'9001','invoice_number'=>'321'];
$ok=SvAmazonErpSalesReturnCanary::evaluate($workflow,[$case],$sale,null);
ecSame(true,$ok['eligible']??null,'valid single-case order must be eligible');
ecSame(77,$ok['case_id']??null,'candidate must expose the exact case ID');
ecSame('701-1234567-1234567',$ok['order_id']??null,'candidate must expose the order ID');
ecSame(false,SvAmazonErpSalesReturnCanary::evaluate($workflow,[$case,['id'=>78]+$case],$sale,null)['eligible']??null,'multi-case refunded order must fail closed');
ecSame(false,SvAmazonErpSalesReturnCanary::evaluate($workflow,[$case],$sale,['invoice_id'=>'999'])['eligible']??null,'existing return invoice must block canary');
ecSame(false,SvAmazonErpSalesReturnCanary::evaluate($workflow,[$case],['order_id'=>'702-0000000-0000000','invoice_id'=>'9001'],null)['eligible']??null,'sale order mismatch must block canary');
ecSame(true,SvAmazonErpSalesReturnCanary::exactWriteScope('77',77),'exact single-case canary scope must pass');
ecSame(false,SvAmazonErpSalesReturnCanary::exactWriteScope('',77),'missing canary scope must fail closed');
ecSame(false,SvAmazonErpSalesReturnCanary::exactWriteScope('77,78',77),'multi-case canary scope must fail closed');
ecSame(false,SvAmazonErpSalesReturnCanary::exactWriteScope('invalid',77),'invalid canary scope must fail closed');
$read=['id'=>'444','idNotaFiscal'=>'9001','itens'=>[['codigo'=>'SKU-1','quantidade'=>1]]];
ecSame(true,SvAmazonErpSalesReturnCanary::verifyExternalReadBack($read,$ok),'matching external readback must pass');
$badQty=$read;$badQty['itens'][0]['quantidade']=2;
ecSame(false,SvAmazonErpSalesReturnCanary::verifyExternalReadBack($badQty,$ok),'quantity drift must fail readback');
$runnerPath=__DIR__.'/../scripts/amazon-returns/erp-sales-return-canary.php';
$runner=is_file($runnerPath)?(string)file_get_contents($runnerPath):'';
ecSame(true,$runner!=='','canary runner script must exist');
foreach(['--mode=discover','--mode=execute','erpSalesReturnCreateEnabled()','writeCaseAllowed','writeAllowedForOrderCases','verifyExternalReadBack'] as $token){
    ecSame(true,str_contains($runner,$token),'canary runner missing safety contract: '.$token);
}
echo "erp-sales-return-canary-test: OK\n";
