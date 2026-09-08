<?php
declare(strict_types=1);
function wbhAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
$installer=(string)file_get_contents(
    dirname(__DIR__).'/scripts/install-amazon-returns-windows-bridge.ps1'
);
$heartbeat=strpos($installer,"'\$readWorker' --heartbeat");
$authCheck=strpos($installer,"'\$readWorker' --auth-check");
$drain=strpos($installer,"'\$readWorker' --drain");
wbhAssert($heartbeat!==false,'Windows dispatcher must emit read-process heartbeat before draining.');
wbhAssert($authCheck!==false,'Windows dispatcher must verify Seller Central browser authentication before draining.');
wbhAssert($drain!==false,'Windows dispatcher must still drain the SAFE-T read worker.');
wbhAssert($heartbeat<$authCheck && $authCheck<$drain,'Heartbeat and browser auth check must precede the read drain.');
$between=substr($installer,$heartbeat,$drain-$heartbeat);
wbhAssert(str_contains($between,'Write-Warning'),'Monitoring/auth-check failures must remain observable while the dispatcher can continue to drain.');
echo "windows-bridge-heartbeat-test: OK\n";
