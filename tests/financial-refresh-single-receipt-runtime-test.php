<?php
declare(strict_types=1);
function frsrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$daemon=(string)file_get_contents(dirname(__DIR__).'/workers/amazon-returns/daemon.php');
frsrAssert(str_contains($daemon,'SvAmazonFinancialRevalidation::sourceEvent('),'SP-API finance refresh must still persist a case-scoped freshness receipt.');
frsrAssert(!str_contains($daemon,'SvAmazonFinancialCheckEvidence::refresh('),'The same SP-API refresh must not persist a second FINANCIAL_REFRESH_CONFIRMED receipt.');
frsrAssert(str_contains($daemon,'SvAmazonFinancialCheckEvidence::reconciled('),'Finance reconciliation audit evidence must remain enabled.');
echo "financial-refresh-single-receipt-runtime-test: OK\n";
