<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnTask.php';
function uiSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m);}
uiSame(true,SvAmazonErpSalesReturnTask::erpReturnExplicitlyIgnored('701-5644155-5071463'),'701 must be explicitly ignored for ERP return.');
uiSame(true,SvAmazonErpSalesReturnTask::erpReturnExplicitlyIgnored('702-8564629-9301052'),'702 must be explicitly ignored for ERP return.');
uiSame(false,SvAmazonErpSalesReturnTask::erpReturnExplicitlyIgnored('702-1111111-2222222'),'Unrelated order must remain eligible.');
uiSame(false,SvAmazonErpSalesReturnTask::workflowProcessable(['status'=>'IGNORED_BY_USER']),'User-ignored ERP return must be terminal.');
echo "erp-sales-return-user-ignore-test: OK\n";
