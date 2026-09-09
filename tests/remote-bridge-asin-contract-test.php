<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';
$row=['id'=>9,'tenant_id'=>1,'amazon_connection_id'=>1,'case_id'=>510,'kind'=>'SELLER_SUPPORT_OPEN','idempotency_key'=>str_repeat('a',64),'attempt_count'=>1,'payload'=>[]];
$case=['amazon_order_id'=>'701-3120107-8909011','amazon_order_item_id'=>'130113962225801','asin'=>'B0CF3XF9T2','safe_t_id'=>null,'support_case_id'=>null,'quantity_refunded'=>1,'quantity_received'=>0];
$job=SvAmazonReturnsRemoteBridge::jobEnvelope($row,$case,['SELLER_SUPPORT_OPEN'=>true]);
if(($job['case']['asin']??null)!=='B0CF3XF9T2'){fwrite(STDERR,'bridge envelope must include scoped case ASIN'.PHP_EOL);exit(1);}
echo "remote-bridge-asin-contract-test: OK\n";