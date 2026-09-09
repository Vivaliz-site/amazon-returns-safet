<?php
declare(strict_types=1);
function scaAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$run=(string)file_get_contents(dirname(__DIR__).'/scripts/amazon-returns/run-seller-central-daily.sh');
scaAssert(str_contains($run,'--no-sandbox'),'Daily runner must disable Chromium userns sandbox on the hardened Ubuntu ARM service host.');
echo "seller-central-apparmor-compat-test: OK\n";
