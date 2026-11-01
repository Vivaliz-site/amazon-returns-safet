<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';
function erpPrioritySame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));}
erpPrioritySame(0,SvAmazonErpSalesReturnTask::workflowPriority(null),'Unseen order must be first.');
erpPrioritySame(0,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'PENDING']),'Pending order must be first.');
erpPrioritySame(1,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'BLOCKED','last_error_code'=>'ERP_ORIGINAL_SALE_NOT_FOUND']),'Retryable missing-sale work must resume before already-saved created returns.');
erpPrioritySame(2,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'READY_TO_CREATE']),'Ready workflow must follow pending/retryable work.');
erpPrioritySame(3,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'RETURN_CREATED_WAITING_INVOICE']),'Created return should be rechecked after unfinished work.');
erpPrioritySame(1,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'BLOCKED','last_error_code'=>'ERP_SALES_RETURN_CREATE_FAILED']),'Uncertain create with target-side recovery must resume before already-saved created returns.');
erpPrioritySame(5,SvAmazonErpSalesReturnTask::workflowPriority(['status'=>'RETURN_INVOICE_EXISTS']),'Completed invoice link should have lowest priority.');
erpPrioritySame('0000-00-00 00:00:00',SvAmazonErpSalesReturnTask::workflowLastCheckedAt(null),'Unseen work must sort ahead of recently checked work.');
erpPrioritySame('2026-09-18 01:00:00',SvAmazonErpSalesReturnTask::workflowLastCheckedAt(['last_checked_at'=>'2026-09-18 01:00:00']),'Persisted check time must drive resume ordering.');

$cases=[['id'=>11],['id'=>12]];
$events=[
  11=>[['event_type'=>'RETURN_REPORT_OBSERVED','source'=>'SP_API_REPORTS','payload'=>['invoice_number'=>'002214']]],
  12=>[['event_type'=>'SALES_INVOICE_LINKED','source'=>'ERP_OLIST_INVOICE','payload'=>['invoice_number'=>'2214']]],
];
erpPrioritySame('002214',SvAmazonErpSalesReturnTask::salesInvoiceNumberFromCases($cases,static fn(int $id):array=>$events[$id]??[]),'Equivalent invoice numbers across cases must collapse to one fallback invoice.');
$events[12]=[['event_type'=>'SALES_INVOICE_LINKED','source'=>'ERP_OLIST_INVOICE','payload'=>['invoice_number'=>'9999']]];
erpPrioritySame(null,SvAmazonErpSalesReturnTask::salesInvoiceNumberFromCases($cases,static fn(int $id):array=>$events[$id]??[]),'Conflicting invoice numbers must disable fallback lookup.');

erpPrioritySame(false,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'RETURN_INVOICE_EXISTS']),'Linked return invoices are terminal and must not spend ERP quota again.');
erpPrioritySame(false,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'RETURN_INVOICE_EXISTS','reconciled_quantity_refunded'=>2],[
    ['quantity_refunded'=>1],['quantity_refunded'=>1],
]),'Already covered refund quantities must not trigger another ERP lookup.');
erpPrioritySame(true,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'RETURN_INVOICE_EXISTS','reconciled_quantity_refunded'=>1],[
    ['quantity_refunded'=>1],['quantity_refunded'=>1],
]),'A later partial refund must reopen ERP reconciliation despite a previously linked NF.');
erpPrioritySame(true,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'RETURN_INVOICE_EXISTS','reconciled_quantity_refunded'=>null],[
    ['quantity_refunded'=>1],
]),'Legacy linked invoices without a quantity baseline must be rechecked once.');

erpPrioritySame(true,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'RETURN_CREATED_WAITING_INVOICE']),'Created returns must remain eligible for return-invoice reconciliation.');
$resume=['metadata'=>['processed_order_ids'=>['702-1111111-2222222','702-3333333-4444444','invalid','702-1111111-2222222']]];
erpPrioritySame(['702-1111111-2222222','702-3333333-4444444'],SvAmazonErpSalesReturnTask::quotaResumeProcessed($resume),'Quota resume must restore only valid unique processed order IDs.');
erpPrioritySame(['702-5555555-6666666'],SvAmazonErpSalesReturnTask::filterQuotaResumeOrders(
    ['702-1111111-2222222','702-5555555-6666666','702-3333333-4444444'],
    ['702-1111111-2222222','702-3333333-4444444']
),'Quota continuation must skip orders already persisted as processed.');

$windowNow=new DateTimeImmutable('2026-09-18 12:00:00',new DateTimeZone('UTC'));
erpPrioritySame(true,SvAmazonErpSalesReturnTask::hasConfirmedRefundQuantity([
    ['quantity_refunded'=>1,'refund_at'=>'2025-08-01 12:00:00'],
]),'A documented old refund is still actionable for ERP return work.');
erpPrioritySame(true,SvAmazonErpSalesReturnTask::hasConfirmedRefundQuantity([
    ['quantity_refunded'=>1,'refund_at'=>'2026-09-18 12:00:00'],
]),'A current refund is actionable for ERP return work.');
erpPrioritySame(true,SvAmazonErpSalesReturnTask::hasConfirmedRefundQuantity([
    ['quantity_refunded'=>1,'refund_at'=>null],
]),'An otherwise confirmed refund cannot be discarded for a missing refund date.');
erpPrioritySame(false,SvAmazonErpSalesReturnTask::hasConfirmedRefundQuantity([
    ['quantity_refunded'=>0,'refund_at'=>'2025-01-01 12:00:00'],
]),'Unconfirmed quantity remains pending evidence, not a created ERP return.');
erpPrioritySame(true,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'IGNORED_REFUND_OUTSIDE_OPERATIONAL_WINDOW']),'Formerly age-ignored refunds must be reprocessed without a date exception.');
erpPrioritySame(true,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'IGNORED_REFUND_OLDER_THAN_90D']),'Historical ninety-day age exemptions must be reprocessed.');
erpPrioritySame(false,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'IGNORED_BY_USER']),'Explicit manual exclusion is not a date filter.');
$taskSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php');
erpPrioritySame(false,str_contains($taskSource,"new DateInterval('P30D')"),'Production ERP workflow must not enforce a 30-day date exclusion.');
erpPrioritySame(true,str_contains($taskSource,"reactivateIgnoredRefund"),'Legacy date-excluded workflows must be reactivated.');
echo "erp-sales-return-priority-test: OK\n";
