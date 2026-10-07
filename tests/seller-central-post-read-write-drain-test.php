<?php
declare(strict_types=1);
function scpwdAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$script=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/run-seller-central-daily.sh');
$first=strpos($script,'seller-central-bridge-worker.mjs');
$read=strpos($script,'seller-central-safe-t-read-worker.mjs" --drain');
$last=strrpos($script,'seller-central-bridge-worker.mjs');
scpwdAssert($first!==false && $read!==false && $last!==false,'Seller Central cycle stages must remain auditable.');
scpwdAssert($first<$read && $read<$last,'The cycle must drain writes again after the long read/discovery drain so retries do not wait eight hours.');
$tail=substr($script,$last,500);
scpwdAssert(str_contains($tail,'--drain'),'The post-read Seller Central write stage must fully drain due writes.');
echo "seller-central-post-read-write-drain-test: OK\n";
