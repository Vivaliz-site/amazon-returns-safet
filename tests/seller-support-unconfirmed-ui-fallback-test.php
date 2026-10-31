<?php
declare(strict_types=1);
function ssufAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'async function supportUpdate');
$end=$start===false?false:strpos($worker,'async function executeJob',$start);
ssufAssert($start!==false && $end!==false,'supportUpdate must remain auditable.');
$update=substr($worker,(int)$start,(int)$end-(int)$start);
ssufAssert(str_contains($update,"if (apiReply.status !== 'UNCONFIRMED')"),'A ReplyCase 2xx without read-back must fall through to the trusted Seller Central UI instead of failing immediately.');
ssufAssert(!str_contains($update,"apiReply.status === 'UNCONFIRMED' ? 'SUPPORT_REPLY_NOT_CONFIRMED'"),'UNCONFIRMED API replies must not short-circuit the UI fallback.');
ssufAssert(str_contains($update,"'Send to Amazon'"),'Seller Central final-send labels must include the observed Send to Amazon control.');
echo "seller-support-unconfirmed-ui-fallback-test: OK\n";
