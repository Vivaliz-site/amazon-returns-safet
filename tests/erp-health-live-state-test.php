<?php
declare(strict_types=1);

function ehlsAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$repo=__DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php';
$runtime=__DIR__.'/../includes/amazon-returns/Runtime.php';
require_once $repo;

ehlsAssert(method_exists(SvAmazonErpSalesReturnRepository::class,'countIncomplete'),'ERP repository must expose current incomplete workflow count.');
$repoSource=(string)file_get_contents($repo);
ehlsAssert(str_contains($repoSource,"status NOT IN ('RETURN_CREATED_WAITING_INVOICE','RETURN_INVOICE_EXISTS')"),'Incomplete count must fail closed for any workflow without proven target-side creation/readback.');

$runtimeSource=(string)file_get_contents($runtime);
ehlsAssert(str_contains($runtimeSource,'$erpIncomplete=$p->erpSalesReturns->countIncomplete();'),'Health must read current ERP workflow state, not only a stale task cursor.');
ehlsAssert(str_contains($runtimeSource,"TASK_ERP_SALES_RETURNS_INCOMPLETE"),'Current incomplete ERP workflows must name a health blocker.');

echo "erp-health-live-state-test: OK\n";
