<?php
declare(strict_types=1);

function ehlsAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$repo=__DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php';
$runtime=__DIR__.'/../includes/amazon-returns/Runtime.php';
require_once $repo;

ehlsAssert(method_exists(SvAmazonErpSalesReturnRepository::class,'countIncomplete'),'ERP repository must expose current incomplete workflow count.');
ehlsAssert(method_exists(SvAmazonErpSalesReturnRepository::class,'healthBreakdown'),'ERP repository must expose status/error/duplicate health breakdown.');
$repoSource=(string)file_get_contents($repo);
ehlsAssert(str_contains($repoSource,"status NOT IN ('RETURN_CREATED_WAITING_INVOICE','RETURN_INVOICE_EXISTS','IGNORED_REFUND_OLDER_THAN_90D')"),'Incomplete count must fail closed for active workflows while excluding refunds intentionally ignored outside the 90-day window.');
ehlsAssert(str_contains($repoSource,"duplicate_groups"),'ERP health breakdown must audit duplicate identifiers, not only aggregate incompletes.');

$runtimeSource=(string)file_get_contents($runtime);
ehlsAssert(str_contains($runtimeSource,'$erpIncomplete=$p->erpSalesReturns->countIncomplete();'),'Health must read current ERP workflow state, not only a stale task cursor.');
ehlsAssert(str_contains($runtimeSource,'$erpBreakdown=$p->erpSalesReturns->healthBreakdown();'),'Health must expose current ERP status/error breakdown.');
ehlsAssert(str_contains($runtimeSource,"TASK_ERP_SALES_RETURNS_INCOMPLETE"),'Current incomplete ERP workflows must name a health blocker.');
ehlsAssert(str_contains($runtimeSource,"ERP_SALES_RETURN_DUPLICATE_IDENTIFIERS"),'Duplicate ERP identifiers must degrade health explicitly.');
ehlsAssert(str_contains($runtimeSource,"'erp_sales_return_breakdown'=>\$erpBreakdown"),'Health payload must publish ERP breakdown for operators.');

echo "erp-health-live-state-test: OK\n";
