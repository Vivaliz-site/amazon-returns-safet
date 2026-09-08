<?php
declare(strict_types=1);
function wbhAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
$installer=(string)file_get_contents(
    dirname(__DIR__).'/scripts/install-amazon-returns-windows-bridge.ps1'
);
$auth=strpos($installer,"'\$readWorker' --auth-check");
$heartbeat=strpos($installer,"'\$readWorker' --heartbeat");
$drain=strpos($installer,"'\$readWorker' --drain");
wbhAssert($auth!==false,'Windows dispatcher must refresh browser-auth health before draining.');
wbhAssert($heartbeat!==false,'Windows dispatcher must emit read-process heartbeat before draining.');
wbhAssert($drain!==false,'Windows dispatcher must still drain the SAFE-T read worker.');
wbhAssert($auth<$heartbeat && $heartbeat<$drain,'Windows health checks must precede the read drain.');
$authSection=substr($installer,$auth,$heartbeat-$auth);
wbhAssert(!str_contains($authSection,'throw'),'A failed browser-auth health check must not abort the Windows drain.');
wbhAssert(str_contains($authSection,'Write-Warning'),'Browser-auth health failure must remain observable while drain continues.');
$heartbeatSection=substr($installer,$heartbeat,$drain-$heartbeat);
wbhAssert(!str_contains($heartbeatSection,'throw'),'A failed monitoring heartbeat must never abort the Windows drain.');
wbhAssert(str_contains($heartbeatSection,'Write-Warning'),'Heartbeat failure must remain observable while drain continues.');
wbhAssert(str_contains($installer,'[int]$PollMinutes = 1440'),'Browser fallback scheduling must default to the approved once-daily cadence.');
$authHelperNeedle='seller-central-auth.mjs';
wbhAssert(str_contains($installer,$authHelperNeedle),'Windows installer must package the shared Seller Central authentication helper.');
wbhAssert(str_contains($installer,'Copy-Item -Force $AuthSource $authHelper'),'Windows installer must copy the auth helper beside both workers.');
echo "windows-bridge-heartbeat-test: OK\n";
