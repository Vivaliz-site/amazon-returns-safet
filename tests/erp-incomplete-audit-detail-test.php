<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php';

function eiadAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

eiadAssert(method_exists(SvAmazonErpSalesReturnRepository::class,'incompleteAuditRows'),'ERP repository must expose sanitized incomplete workflow audit rows.');

$repo=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php');
eiadAssert(str_contains($repo,'amazon_order_id,status,last_error_code'),'Incomplete workflow audit must expose only sanitized operational fields.');
eiadAssert(str_contains($repo,"status NOT IN ('RETURN_CREATED_WAITING_INVOICE','RETURN_INVOICE_EXISTS','IGNORED_REFUND_OLDER_THAN_90D')"),'Incomplete audit must use the same completion boundary as countIncomplete(), including the 90-day ignored terminal state.');

$task=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php');
eiadAssert(str_contains($task,"['incomplete_workflows_detail']"),'ERP task must publish sanitized incomplete workflow detail.');
eiadAssert(str_contains($task,'incompleteAuditRows()'),'ERP task must source detail from the tenant-scoped repository.');

echo "erp-incomplete-audit-detail-test: OK\n";
