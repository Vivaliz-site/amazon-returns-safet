<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';

function erpeoSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
function erpeoAssert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

erpeoAssert(method_exists(SvAmazonErpSalesReturnTask::class,'safeErrorCode'),'ERP task must expose a sanitized error classifier for production observability.');
if(method_exists(SvAmazonErpSalesReturnTask::class,'safeErrorCode')){
    erpeoSame('ERP_RETURN_INVOICE_HTTP_401',SvAmazonErpSalesReturnTask::safeErrorCode(new RuntimeException('ERP return invoice lookup failed with HTTP 401.')),'List lookup HTTP status must remain observable without leaking response data.');
    erpeoSame('ERP_RETURN_INVOICE_DETAIL_HTTP_403',SvAmazonErpSalesReturnTask::safeErrorCode(new RuntimeException('ERP return invoice detail lookup failed with HTTP 403.')),'Detail lookup HTTP status must remain observable.');
    erpeoSame('ERP_CREDENTIALS_UNAVAILABLE',SvAmazonErpSalesReturnTask::safeErrorCode(new RuntimeException('ERP access token is not configured.')),'Credential failures must collapse to a non-secret code.');
    erpeoSame('ERP_RETURN_INVOICE_AMBIGUOUS',SvAmazonErpSalesReturnTask::safeErrorCode(new UnexpectedValueException('ERP order maps to multiple return invoices.')),'Ambiguous return invoices must have an explicit safe code.');
    erpeoSame('ERP_RUNTIME_EXCEPTION',SvAmazonErpSalesReturnTask::safeErrorCode(new RuntimeException('unexpected private detail')),'Unknown exception text must never be echoed.');
}

$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php');
erpeoAssert(str_contains($source,"'error_code'=>self::safeErrorCode(\$e)"),'Per-order errors must retain only the sanitized error code.');
erpeoAssert(str_contains($source,"'error_codes'"),'ERP task summary must expose counts by sanitized error code.');

echo "erp-sales-return-error-observability-test: OK\n";
