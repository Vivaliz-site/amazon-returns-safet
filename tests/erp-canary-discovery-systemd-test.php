<?php
declare(strict_types=1);
function edAssert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$unitPath=__DIR__.'/../deploy/systemd/amazon-returns-erp-canary-discovery.service';
$unit=is_file($unitPath)?(string)file_get_contents($unitPath):'';
edAssert($unit!=='','ERP canary discovery oneshot unit must exist.');
edAssert(str_contains($unit,'User=www-data'),'Discovery must run with the application service identity.');
edAssert(str_contains($unit,'EnvironmentFile=-/home/ubuntu/amazon-returns-deploy/shared/.env'),'Discovery must use the canonical production environment.');
edAssert(str_contains($unit,'--mode=discover'),'Discovery unit must be read-only mode.');
edAssert(!str_contains($unit,'--mode=execute'),'Discovery unit must never execute ERP writes.');
$provision=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
edAssert(str_contains($provision,'amazon-returns-erp-canary-discovery.service'),'Production provision must install the discovery unit.');
edAssert(str_contains($provision,'erp_canary_discovery='),'Production provision must emit discovery start status without blocking the release.');
echo "erp-canary-discovery-systemd-test: OK\n";
