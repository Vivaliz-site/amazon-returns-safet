<?php
declare(strict_types=1);
function scrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$run=(string)file_get_contents(dirname(__DIR__).'/scripts/amazon-returns/run-seller-central-daily.sh');
scrAssert(!preg_match('/^\/usr\/bin\/node\s/m',$run),'Daily runner must not invoke distro Node 18 directly.');
scrAssert(str_contains($run,'SELLER_CENTRAL_NODE_BINARY'),'Daily runner must support explicit Node runtime selection.');
scrAssert(str_contains($run,'typeof WebSocket'),'Daily runner must reject Node runtimes without global WebSocket support.');
scrAssert(substr_count($run,'"$NODE_BIN"')>=3,'All Seller Central workers must use the validated Node runtime.');
echo "seller-central-runtime-contract-test: OK\n";
