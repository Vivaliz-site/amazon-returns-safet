<?php
declare(strict_types=1);

function isoAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$service=(string)file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-deploy.service');
$script=(string)file_get_contents(__DIR__.'/../scripts/auto-deploy.sh');
$provision=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
$bootstrapPath=__DIR__.'/../scripts/ensure-auto-deploy-source.sh';
isoAssert(is_file($bootstrapPath),'Dedicated deploy-source bootstrap script must exist.');
$bootstrap=(string)file_get_contents($bootstrapPath);

isoAssert(str_contains($service,'Environment=AMAZON_RETURNS_REPO=/home/ubuntu/amazon-returns-deploy-source'),'Deploy service must pin the dedicated source checkout.');
isoAssert(str_contains($service,'ExecStart=/home/ubuntu/amazon-returns-deploy-source/scripts/auto-deploy.sh'),'Deploy service must execute auto-deploy from the dedicated source checkout.');
isoAssert(!str_contains($service,'ExecStart=/home/ubuntu/amazon-returns-safet/scripts/auto-deploy.sh'),'Deploy service must never execute from the agent work checkout.');
isoAssert(str_contains($script,'${AMAZON_RETURNS_REPO:-/home/ubuntu/amazon-returns-deploy-source}'),'Auto-deploy safe default must be the dedicated source checkout.');
isoAssert(str_contains($script,'status --porcelain'),'Auto-deploy must reject untracked or modified files in the dedicated checkout.');
isoAssert(str_contains($bootstrap,'deploy_source="${AMAZON_RETURNS_DEPLOY_SOURCE_REPO:-/home/ubuntu/amazon-returns-deploy-source}"'),'Bootstrap must own the dedicated deploy source path.');
isoAssert(str_contains($bootstrap,'git clone') && str_contains($bootstrap,'Vivaliz-site/amazon-returns-safet'),'Bootstrap must clone the canonical repository when missing.');
isoAssert(str_contains($bootstrap,'status --porcelain'),'Bootstrap must reject unexpected dirt in the dedicated checkout.');
isoAssert(str_contains($bootstrap,'merge --ff-only'),'Bootstrap must advance main without destructive reset.');
isoAssert(str_contains($provision,'ensure-auto-deploy-source.sh'),'Production provisioner must bootstrap the isolated deploy source before enabling its timer.');
isoAssert(!str_contains($provision,"git -C /home/ubuntu/amazon-returns-safet reset --hard"),'Provisioner must never hard-reset the agent work checkout.');
isoAssert(!str_contains($provision,"git -C /home/ubuntu/amazon-returns-safet clean"),'Provisioner must never clean the agent work checkout.');

echo "auto-deploy-isolation-contract-test: OK\n";