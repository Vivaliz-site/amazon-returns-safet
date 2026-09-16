<?php
declare(strict_types=1);
$script=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
function ebpAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$swap='mv -Tf "$root/current.next" "$root/current"';
$provision='provision-olist-erp-browser-host.sh';
$swapPos=strpos($script,$swap);
$provisionPos=strpos($script,$provision);
ebpAssert($provisionPos!==false,'Production provision must invoke the Olist ERP browser provisioner.');
ebpAssert($swapPos!==false && $provisionPos>$swapPos,'ERP browser must be provisioned against the activated release.');
ebpAssert(str_contains($script,'--enable-service'),'Production provisioning must enable the persistent ERP browser service.');
ebpAssert(str_contains($script,'systemctl is-active --quiet amazon-returns-olist-erp-browser.service'),'Provisioning must verify ERP browser service is actually active.');
echo "erp-browser-production-provision-test: OK\n";