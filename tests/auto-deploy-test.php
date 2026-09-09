<?php
declare(strict_types=1);

function adAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$script=__DIR__.'/../scripts/auto-deploy.sh';
$service=__DIR__.'/../deploy/systemd/amazon-returns-deploy.service';
$timer=__DIR__.'/../deploy/systemd/amazon-returns-deploy.timer';
adAssert(is_file($script),'Standalone auto-deploy script must exist.');
adAssert(is_executable($script),'Standalone auto-deploy script must be executable by systemd.');
adAssert(is_file($service),'Standalone auto-deploy service must exist.');
adAssert(is_file($timer),'Standalone auto-deploy timer must exist.');
$s=(string)file_get_contents($script);
$svc=(string)file_get_contents($service);
$t=(string)file_get_contents($timer);
adAssert(str_contains($s,'Vivaliz-site/amazon-returns-safet'),'Auto-deploy must validate target repo CI.');
adAssert(str_contains($s,'check-runs'),'Auto-deploy must require GitHub checks before deploying.');
adAssert(str_contains($s,'AMAZON_RETURNS_IMPORT_SOURCE=0'),'Routine deploy must never re-import website state.');
adAssert(str_contains($svc,'/home/ubuntu/amazon-returns-safet/scripts/auto-deploy.sh'),'Deploy service must be owned by target repo checkout.');
adAssert(str_contains($t,'OnUnitActiveSec=3600'),'Deploy cadence must be hourly.');
adAssert(!str_contains($t,'OnUnitActiveSec=300'),'Deploy cadence must never regress to five minutes.');
adAssert(!str_contains($svc,'shopvivaliz'),'Deploy service cannot own website lifecycle.');

echo "auto-deploy-test: OK\n";