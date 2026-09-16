<?php
declare(strict_types=1);
$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
function ohrAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
ohrAssert(str_contains($runtime,"require_once __DIR__ . '/OperationalHealth.php';"),'Runtime must load OperationalHealth.');
ohrAssert(str_contains($runtime,"'OPERATIONAL_TASK'"),'Runtime health must read per-task operational observations.');
ohrAssert(str_contains($runtime,'SvAmazonOperationalHealth::evaluate'),'Runtime health must evaluate task freshness/failures/dead letters.');
ohrAssert(str_contains($runtime,'$operationalHealth[\'blockers\']'),'Runtime health must merge operational blockers into business health.');
echo "operational-health-runtime-contract-test: OK\n";