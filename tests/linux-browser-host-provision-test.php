<?php
declare(strict_types=1);
function lbAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$runner=$root.'/scripts/amazon-returns/run-seller-central-daily.sh';
$provision=$root.'/scripts/provision-seller-central-browser-host.sh';
$service=$root.'/deploy/systemd/amazon-returns-seller-central-browser.service';
$timer=$root.'/deploy/systemd/amazon-returns-seller-central-browser.timer';
foreach([$runner,$provision,$service,$timer] as $path)lbAssert(is_file($path),'Missing Linux browser host artifact: '.basename($path));
$run=(string)file_get_contents($runner);
lbAssert(substr_count($run,'--drain')>=2,'Daily runner must drain read and write workers serially.');
lbAssert(strpos($run,'seller-central-safe-t-read-worker.mjs')<strpos($run,'seller-central-bridge-worker.mjs'),'Daily runner must refresh Seller Central status before draining writes.');
lbAssert(str_contains($run,'SELLER_CENTRAL_BROWSER'),'Runner must use a configurable browser executable.');
lbAssert(str_contains($run,'SELLER_CENTRAL_PROFILE'),'Runner must use a persistent dedicated profile.');
lbAssert(str_contains($run,'browser_pid'),'Runner must track the dedicated browser PID it owns.');
lbAssert(!str_contains($run,'pkill'),'Runner must never kill unrelated browser processes.');
$svc=(string)file_get_contents($service);
lbAssert(str_contains($svc,'Type=oneshot'),'Browser service must be finite.');
lbAssert(str_contains($svc,'EnvironmentFile=-/home/ubuntu/amazon-returns-deploy/shared/seller-central-browser.env'),'Service must load path-only runtime configuration.');
lbAssert(str_contains($svc,'NoNewPrivileges=true'),'Browser service must keep privilege hardening.');
$tmr=(string)file_get_contents($timer);
lbAssert(str_contains($tmr,'OnCalendar=*-*-* 08:00:00 America/Sao_Paulo'),'Browser timer must run once daily at the documented Brazil time.');
lbAssert(str_contains($tmr,'Persistent=true'),'Missed daily run must recover after downtime.');
$prov=(string)file_get_contents($provision);
foreach(['SELLER_CENTRAL_TOTP_HOST','SELLER_CENTRAL_TOTP_KEY_FILE','SELLER_CENTRAL_TOTP_KNOWN_HOSTS_FILE','SELLER_CENTRAL_USERNAME_FILE','SELLER_CENTRAL_PASSWORD_FILE'] as $needle){
    lbAssert(str_contains($prov,$needle),'Provisioner must configure secret references, not inline secrets: '.$needle);
}
lbAssert(!preg_match('/SELLER_CENTRAL_PASSWORD=[^_]/',$prov),'Provisioner must not inline an Amazon password.');
lbAssert(!str_contains($prov,'StrictHostKeyChecking=no'),'Provisioner must never weaken TOTP host verification.');
$prod=(string)file_get_contents($root.'/scripts/provision-production.sh');
foreach(['amazon-returns-seller-central-browser.service','amazon-returns-seller-central-browser.timer'] as $unit){
    lbAssert(str_contains($prod,$unit),'Production provisioning must install browser unit: '.$unit);
}
lbAssert(!str_contains($prod,'enable --now amazon-returns-seller-central-browser.timer'),'Production deploy must not activate browser automation before TOTP enrollment.');
echo "linux-browser-host-provision-test: OK\n";
