<?php
declare(strict_types=1);

function isoAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$service=(string)file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-deploy.service');
$script=(string)file_get_contents(__DIR__.'/../scripts/auto-deploy.sh');
$provision=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');

isoAssert(str_contains($service,'Environment=AMAZON_RETURNS_REPO=/home/ubuntu/amazon-returns-deploy-source'),'Deploy service must pin the dedicated source checkout.');
isoAssert(str_contains($service,'ExecStart=/home/ubuntu/amazon-returns-deploy-source/scripts/auto-deploy.sh'),'Deploy service must execute auto-deploy from the dedicated source checkout.');
isoAssert(!str_contains($service,'ExecStart=/home/ubuntu/amazon-returns-safet/scripts/auto-deploy.sh'),'Deploy service must never execute from the agent work checkout.');
isoAssert(str_contains($script,'${AMAZON_RETURNS_REPO:-/home/ubuntu/amazon-returns-deploy-source}'),'Auto-deploy safe default must be the dedicated source checkout.');
isoAssert(str_contains($provision,"deploy_source='/home/ubuntu/amazon-returns-deploy-source'"),'Production provisioner must own the dedicated deploy source path.');
isoAssert(str_contains($provision,'git clone') && str_contains($provision,'Vivaliz-site/amazon-returns-safet'),'Provisioner must bootstrap the dedicated source from the canonical repository.');
isoAssert(!str_contains($provision,"git -C /home/ubuntu/amazon-returns-safet reset --hard"),'Provisioner must never hard-reset the agent work checkout.');
isoAssert(!str_contains($provision,"git -C /home/ubuntu/amazon-returns-safet clean"),'Provisioner must never clean the agent work checkout.');

echo "auto-deploy-isolation-contract-test: OK\n";