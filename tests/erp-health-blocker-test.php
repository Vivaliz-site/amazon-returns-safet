<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';
require_once __DIR__.'/../includes/amazon-returns/PublicHealthResponse.php';

function ehAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ehSame(mixed $want,mixed $got,string $message):void{if($want!==$got)throw new RuntimeException($message.' expected='.var_export($want,true).' got='.var_export($got,true));}

$method=new ReflectionMethod(SvAmazonErpSalesReturnTask::class,'result');
$blocked=$method->invoke(null,[['status'=>'BLOCKED']],true,false);
ehSame('PARTIAL',$blocked['status']??null,'Any blocked mandatory ERP return must degrade the task.');
$ready=$method->invoke(null,[['status'=>'READY_TO_CREATE']],true,false);
ehSame('PARTIAL',$ready['status']??null,'An unexecuted ready ERP return must degrade the task.');
$done=$method->invoke(null,[['status'=>'RETURN_CREATED_WAITING_INVOICE'],['status'=>'RETURN_INVOICE_EXISTS']],true,false);
ehSame('OK',$done['status']??null,'Externally created/read-back returns may keep the task healthy.');

$public=SvAmazonReturnsPublicHealthResponse::fromRuntime([
    'status'=>'DEGRADED',
    'health_blockers'=>['TASK_ERP_SALES_RETURNS_PARTIAL','WRITE_GATE_X_DISABLED','TASK_ERP_SALES_RETURNS_PARTIAL'],
]);
ehSame('DEGRADED',$public['status']??null,'Public health must preserve DEGRADED.');
ehSame(['TASK_ERP_SALES_RETURNS_PARTIAL','WRITE_GATE_X_DISABLED'],$public['blockers']??null,'Public health must expose deduplicated blocker names.');

$ok=SvAmazonReturnsPublicHealthResponse::fromRuntime(['status'=>'OK','health_blockers'=>[]]);
ehSame([],$ok['blockers']??null,'Healthy response must expose an empty blocker list.');
ehAssert(array_keys($ok)===['service','status','blockers'],'Public health contract must stay intentionally minimal.');
echo "erp-health-blocker-test: OK\n";
