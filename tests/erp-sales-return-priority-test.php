<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';
function erpPrioritySame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}
erpPrioritySame(0,SvAmazonErpSalesReturnTask::workflowPriority(null),'Unseen order must be first.');
erpPrioritySame(0,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'PENDING']),'Pending order must be first.');
erpPrioritySame(1,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'BLOCKED','last_error_code'=>'ERP_ORIGINAL_SALE_NOT_FOUND']),'Retryable missing-sale work must resume before already-saved created returns.');
erpPrioritySame(2,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'READY_TO_CREATE']),'Ready workflow must follow pending/retryable work.');
erpPrioritySame(3,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'RETURN_CREATED_WAITING_INVOICE']),'Created return should be rechecked after unfinished work.');
erpPrioritySame(4,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'BLOCKED','last_error_code'=>'ERP_SALES_RETURN_CREATE_FAILED']),'Non-retryable blocked workflow must not starve unfinished orders.');
erpPrioritySame(5,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'RETURN_INVOICE_EXISTS']),'Completed invoice link should have lowest priority.');
erpPrioritySame('0000-00-00 00:00:00',SvAmazonErpSalesReturnTask::workflowLastCheckedAt(null),'Unseen work must sort ahead of recently checked work.');
erpPrioritySame('2026-09-18 01:00:00',SvAmazonErpSalesReturnTask::workflowLastCheckedAt(['last_checked_at'=>'2026-09-18 01:00:00']),'Persisted check time must drive resume ordering.');
echo "erp-sales-return-priority-test: OK\n";
