<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$runner=(string)file_get_contents($root.'/scripts/amazon-returns/run-seller-central-daily.sh');
if(!str_contains($runner,'setsid')) throw new RuntimeException('Dedicated Seller Central browser must run in its own process group.');
if(!str_contains($runner,'kill -TERM -- "-$browser_pid"')) throw new RuntimeException('Cleanup must terminate the whole dedicated browser process group.');
if(!str_contains($runner,'kill -KILL -- "-$browser_pid"')) throw new RuntimeException('Cleanup must have a bounded hard-stop fallback for browser children.');
if(str_contains($runner,'pkill')) throw new RuntimeException('Cleanup must not use broad pkill matching.');
$service=(string)file_get_contents($root.'/deploy/systemd/amazon-returns-seller-central-browser.service');
if(!str_contains($service,'KillMode=control-group')) throw new RuntimeException('Systemd must kill the entire dedicated browser cgroup on service stop.');
if(!str_contains($service,'TimeoutStopSec=15s')) throw new RuntimeException('Systemd browser shutdown must have a bounded stop timeout.');
echo "browser-process-lifecycle-contract-test: OK\n";
