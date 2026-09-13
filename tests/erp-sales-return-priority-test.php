<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';
function erpPrioritySame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}
erpPrioritySame(0,SvAmazonErpSalesReturnTask::workflowPriority(null),'Unseen order must be first.');
erpPrioritySame(0,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'PENDING']),'Pending order must be first.');
erpPrioritySame(1,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'READY_TO_CREATE']),'Ready workflow must follow pending work.');
erpPrioritySame(2,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'RETURN_CREATED_WAITING_INVOICE']),'Created return should still be checked for its NF.');
erpPrioritySame(3,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'BLOCKED']),'Blocked workflow must not starve unfinished orders.');
erpPrioritySame(4,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'RETURN_INVOICE_EXISTS']),'Completed invoice link should have lowest priority.');
echo "erp-sales-return-priority-test: OK\n";
