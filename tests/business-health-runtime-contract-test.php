<?php
declare(strict_types=1);
$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
function bhrAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
bhrAssert(str_contains($runtime,"require_once __DIR__ . '/BusinessHealth.php';"),'Runtime must load BusinessHealth.');
bhrAssert(str_contains($runtime,'SvAmazonBusinessHealth::evaluate'),'Runtime health must aggregate business capability health.');
bhrAssert(str_contains($runtime,"'health_blockers'"),'Runtime health must expose exact blockers.');
$browserOnly='$healthStatus=($browserLiveness[\'status\'] ?? \'\')===\'DEGRADED\' ? \'DEGRADED\' : \'OK\';';
bhrAssert(!str_contains($runtime,$browserOnly),'Runtime health cannot depend only on browser liveness.');
echo "business-health-runtime-contract-test: OK\n";