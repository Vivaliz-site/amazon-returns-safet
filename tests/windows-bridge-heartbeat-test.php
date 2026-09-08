<?php
declare(strict_types=1);
function wbhAssert(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
}
$installer=(string)file_get_contents(
    dirname(__DIR__).'/scripts/install-amazon-returns-windows-bridge.ps1'
);
$heartbeat=strpos($installer,"'\$readWorker' --heartbeat");
$drain=strpos($installer,"'\$readWorker' --drain");
wbhAssert($heartbeat!==false,'Windows dispatcher must emit read-process heartbeat before draining.');
wbhAssert($drain!==false,'Windows dispatcher must still drain the SAFE-T read worker.');
wbhAssert($heartbeat<$drain,'Windows dispatcher heartbeat must precede the read drain.');
$between=substr($installer,$heartbeat,$drain-$heartbeat);
wbhAssert(!str_contains($between,'throw'),'A failed monitoring heartbeat must never abort the Windows drain.');
wbhAssert(str_contains($between,'Write-Warning'),'Heartbeat failure must remain observable while drain continues.');
echo "windows-bridge-heartbeat-test: OK\n";
