<?php
declare(strict_types=1);
function bhAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$bridge=(string)file_get_contents($root.'/includes/amazon-returns/BridgeService.php');
$status=(string)file_get_contents($root.'/includes/amazon-returns/StatusBridgeService.php');
$runtime=(string)file_get_contents($root.'/includes/amazon-returns/Runtime.php');
$bridgeApi=(string)file_get_contents($root.'/api/amazon-returns/bridge.php');
$statusApi=(string)file_get_contents($root.'/api/amazon-returns/status-bridge.php');
$reader=(string)file_get_contents($root.'/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs');
$runner=(string)file_get_contents($root.'/scripts/amazon-returns/run-seller-central-daily.sh');

bhAssert(str_contains($bridge,"'write_process_heartbeat'"),'Write bridge heartbeat must persist worker liveness.');
bhAssert(str_contains($status,"'read_process_heartbeat'"),'Read bridge heartbeat must persist worker liveness.');
bhAssert(str_contains($status,"'browser_auth'"),'Authenticated browser checks must persist separately from process liveness.');
bhAssert(str_contains($bridgeApi,"\$input['worker_id']"),'Write bridge endpoint must pass worker identity to heartbeat service.');
bhAssert(str_contains($statusApi,"\$input['worker_id']"),'Read bridge endpoint must pass worker identity to heartbeat service.');
bhAssert(str_contains($statusApi,"\$input['auth_status']"),'Status endpoint must pass non-secret browser auth outcome.');
bhAssert(str_contains($reader,'--auth-check'),'Read worker must provide an authenticated browser health check.');
bhAssert(str_contains($reader,'auth_status: auth.status'),'Auth check must report the non-secret auth result to the backend.');
bhAssert(str_contains($runner,'--auth-check'),'Daily browser cycle must authenticate before draining jobs.');
bhAssert(str_contains($runtime,'BridgeLiveness.php'),'Runtime health must load authenticated bridge liveness logic.');
bhAssert(str_contains($runtime,"'seller_central_browser'"),'Health payload must expose Seller Central browser liveness.');
bhAssert(str_contains($runtime,"'DEGRADED'"),'Health must support a degraded state.');
echo "bridge-heartbeat-contract-test: OK\n";
