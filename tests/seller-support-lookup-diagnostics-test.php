<?php
declare(strict_types=1);
function sldAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
sldAssert(str_contains($worker,'lookupReason'),'Deep Seller Support lookup must preserve its internal failure reason.');
sldAssert(str_contains($worker,"lookup_reason: text(error?.lookupReason"),'Bridge results must expose the safe lookup failure reason.');
sldAssert(str_contains($worker,'lookup_reason: data.lookup_reason ?? null'),'Structured worker logs must include the safe lookup reason.');
echo "seller-support-lookup-diagnostics-test: OK\n";
