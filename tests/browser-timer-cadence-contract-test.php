<?php
declare(strict_types=1);
function btcAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$timer=(string)file_get_contents(dirname(__DIR__).'/deploy/systemd/amazon-returns-seller-central-browser.timer');
btcAssert(str_contains($timer,'OnCalendar=*-*-* 08:00:00 America/Sao_Paulo'),'Seller Central browser must run at 08:00 Brazil time.');
btcAssert(str_contains($timer,'OnCalendar=*-*-* 20:00:00 America/Sao_Paulo'),'Seller Central browser must run again at 20:00 Brazil time.');
btcAssert(substr_count($timer,'OnCalendar=')===2,'Seller Central browser business cadence must be exactly twice per day.');
btcAssert(str_contains($timer,'Persistent=true'),'Missed browser cycles must recover after VM downtime.');
echo "browser-timer-cadence-contract-test: OK\n";
