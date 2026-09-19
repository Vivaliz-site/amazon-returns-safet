<?php
declare(strict_types=1);

function dtcAssert(bool $ok,string $message):void{
    if(!$ok)throw new RuntimeException($message);
}

$timer=(string)file_get_contents(dirname(__DIR__).'/deploy/systemd/amazon-returns-deploy.timer');
$provision=(string)file_get_contents(dirname(__DIR__).'/scripts/provision-production.sh');

dtcAssert(str_contains($timer,'OnUnitActiveSec=300'),'Auto-deploy must poll main every five minutes.');
dtcAssert(str_contains($timer,'Persistent=true'),'Auto-deploy timer must remain persistent across downtime.');
dtcAssert(str_contains($timer,'AccuracySec=30'),'Auto-deploy cadence must retain bounded timer jitter.');
dtcAssert(str_contains($provision,'systemctl enable --now amazon-returns-deploy.timer'),'Provisioning must enable the updated deploy timer.');
dtcAssert(!str_contains($timer,'OnUnitActiveSec=3600'),'Legacy hourly deploy polling must not remain active.');

echo "deploy-timer-cadence-test: OK\n";
