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
adAssert(str_contains($svc,'/home/ubuntu/amazon-returns-deploy-source/scripts/auto-deploy.sh'),'Deploy service must be owned by isolated deploy checkout.');
adAssert(str_contains($svc,'Environment=AMAZON_RETURNS_REPO=/home/ubuntu/amazon-returns-deploy-source'),'Deploy service must pin isolated checkout through the environment.');
adAssert(!str_contains($svc,'/home/ubuntu/amazon-returns-safet/scripts/auto-deploy.sh'),'Deploy service must not depend on the agent work checkout.');
adAssert(str_contains($t,'OnUnitActiveSec=3600'),'Deploy cadence must be hourly.');
adAssert(!str_contains($t,'OnUnitActiveSec=300'),'Deploy cadence must never regress to five minutes.');
adAssert(!str_contains($svc,'shopvivaliz'),'Deploy service cannot own website lifecycle.');
adAssert(str_contains($s,'retry_seller_central_browser_if_failed'),'Already-current deploy cycles must retry a previously failed Seller Central unit.');
adAssert(str_contains($s,'systemctl is-failed --quiet amazon-returns-seller-central-auth-check.service'),'Seller Central retry must be conditional on a failed auth-check unit.');
$alreadyCurrentBlock='if [[ "$target_sha" == "$deployed_sha" ]]; then'."\n".'    retry_seller_central_browser_if_failed'."\n"."    echo 'auto_deploy_skipped=already_current'";
adAssert(str_contains($s,$alreadyCurrentBlock),'Failed Seller Central recovery must run before the already-current early exit.');
$ciGateStart=strpos($s,'if ! jq -e');
$ciSkip=strpos($s,"echo 'auto_deploy_skipped=ci_not_green'",$ciGateStart===false?0:$ciGateStart);
adAssert($ciGateStart!==false && $ciSkip!==false,'CI-not-green gate must remain auditable.');
$ciGate=substr($s,(int)$ciGateStart,(int)$ciSkip-(int)$ciGateStart);
adAssert(str_contains($ciGate,'retry_seller_central_browser_if_failed'),'A failed current Seller Central runtime must recover even when the candidate main SHA is not CI-green.');

echo "auto-deploy-test: OK\n";
