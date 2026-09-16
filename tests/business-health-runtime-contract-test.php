<?php
declare(strict_types=1);

$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
$config=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Config.php');

function bhrAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

bhrAssert(str_contains($runtime,"require_once __DIR__ . '/BusinessHealth.php';"),'Runtime must load business health aggregation.');
bhrAssert(str_contains($runtime,'SvAmazonBusinessHealth::evaluate('),'Runtime health must evaluate business capabilities.');
bhrAssert(str_contains($runtime,"'health_blockers'=>\$businessHealth['blockers']"),'Runtime health must expose blocking capabilities.');
bhrAssert(str_contains($runtime,"'write_flags'=>\$writeFlags"),'Runtime must expose the same effective flags used by health.');
bhrAssert(str_contains($config,"'ERP_SALES_RETURN_CREATE' => \$this->erpSalesReturnCreateEnabled()"),'ERP health flag must include dedicated gate.');

echo "business-health-runtime-contract-test: OK\n";
