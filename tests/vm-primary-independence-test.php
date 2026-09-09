<?php
declare(strict_types=1);
function vpiAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$bridge=(string)file_get_contents($root.'/scripts/amazon-returns/seller-central-bridge-worker.mjs');
$status=(string)file_get_contents($root.'/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
$daemon=(string)file_get_contents($root.'/workers/amazon-returns/daemon.php');
$provision=(string)file_get_contents($root.'/scripts/provision-seller-central-browser-host.sh');
foreach([$bridge,$status] as $worker){
    vpiAssert(!str_contains($worker,'fred-win-'),'Primary runtime worker must not default to Fred-Win identity.');
    vpiAssert(!str_contains($worker,'C:\\\\ShopVivaliz\\\\amazon-returns-bridge'),'Primary runtime worker must not carry a Windows-only path default.');
}
vpiAssert(!str_contains($status,'LOCALAPPDATA'),'Primary status worker must not auto-discover a Windows browser.');
vpiAssert(!str_contains($daemon,'WINDOWS_BRIDGE_OWNS_OUTBOX'),'Daemon runtime state must not imply Windows owns Seller Central jobs.');
vpiAssert(str_contains($daemon,'SELLER_CENTRAL_BRIDGE_OWNS_OUTBOX'),'Daemon must describe the host-neutral bridge ownership model.');
vpiAssert(str_contains($provision,'SELLER_CENTRAL_WORKER_ID=vm-a1-seller-central'),'Primary VM worker identity must be explicit.');
vpiAssert(str_contains($provision,'SELLER_CENTRAL_STATUS_WORKER_ID=vm-a1-safe-t-status'),'Primary VM status worker identity must be explicit.');
vpiAssert(str_contains($provision,'SELLER_CENTRAL_TOTP_HOST=$TOTP_HOST'),'Primary VM must request TOTP from the authenticator VM.');
echo "vm-primary-independence-test: OK\n";
