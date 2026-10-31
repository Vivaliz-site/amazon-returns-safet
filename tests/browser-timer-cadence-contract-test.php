<?php
declare(strict_types=1);
function btcAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$timer=(string)file_get_contents(dirname(__DIR__).'/deploy/systemd/amazon-returns-seller-central-browser.timer');
btcAssert(str_contains($timer,'OnBootSec=2m'),'Seller Central browser must begin shortly after boot.');
btcAssert(str_contains($timer,'OnUnitInactiveSec=8h'),'Seller Central browser cycle must run every eight hours as the approved operational cadence.');
btcAssert(!str_contains($timer,'OnUnitInactiveSec=5m'),'Legacy five-minute Seller Central polling must not return.');
btcAssert(str_contains($timer,'Persistent=true'),'Missed browser cycles must recover after VM downtime.');
btcAssert(str_contains($timer,'AccuracySec=30s'),'Responsive browser cadence must keep bounded timer jitter.');
btcAssert(str_contains($timer,'RandomizedDelaySec=30s'),'Browser starts must retain a small anti-thundering-herd jitter.');
echo "browser-timer-cadence-contract-test: OK\n";
