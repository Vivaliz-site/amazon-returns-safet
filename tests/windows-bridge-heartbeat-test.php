<?php
declare(strict_types=1);
function wbhAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
$installer=(string)file_get_contents(
    dirname(__DIR__).'/scripts/install-amazon-returns-windows-bridge.ps1'
);
$heartbeat=strpos($installer,"'\$readWorker' --heartbeat");
$auth=strpos($installer,"'\$readWorker' --auth-check");
$drain=strpos($installer,"'\$readWorker' --drain");
wbhAssert($heartbeat!==false,'Windows dispatcher must emit read-process heartbeat before draining.');
wbhAssert($auth!==false,'Windows dispatcher must verify Seller Central browser authentication before draining.');
wbhAssert($drain!==false,'Windows dispatcher must still drain the SAFE-T read worker.');
wbhAssert($heartbeat<$auth && $auth<$drain,'Heartbeat and browser auth check must precede the read drain.');
$heartbeatSection=substr($installer,$heartbeat,$auth-$heartbeat);
wbhAssert(!str_contains($heartbeatSection,'throw'),'A failed monitoring heartbeat must never abort the Windows drain.');
wbhAssert(str_contains($heartbeatSection,'Write-Warning'),'Heartbeat failure must remain observable while drain continues.');
$authSection=substr($installer,$auth,$drain-$auth);
wbhAssert(!str_contains($authSection,'throw'),'A failed browser-auth health check must not abort the Windows drain.');
wbhAssert(str_contains($authSection,'Write-Warning'),'Browser-auth health failure must remain observable while drain continues.');
wbhAssert(str_contains($installer,'[int]$PollMinutes = 1440'),'Browser fallback scheduling must default to the approved once-daily cadence.');
wbhAssert(str_contains($installer,'seller-central-auth.mjs'),'Windows installer must package the shared Seller Central authentication helper.');
wbhAssert(str_contains($installer,'Copy-Item -Force $AuthSource $authHelper'),'Windows installer must copy the auth helper beside both workers.');
echo "windows-bridge-heartbeat-test: OK\n";
