<?php
declare(strict_types=1);

function ssafAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$apiStart=strpos($worker,'async function submitSupportEmailReplyApi');
$updateStart=strpos($worker,'async function supportUpdate');
$executeStart=$updateStart===false?false:strpos($worker,'async function executeJob',$updateStart);
ssafAssert($apiStart!==false && $updateStart!==false && $executeStart!==false,'Seller Support API/update flow must remain auditable.');
$api=substr($worker,(int)$apiStart,(int)$updateStart-(int)$apiStart);
$update=substr($worker,(int)$updateStart,(int)$executeStart-(int)$updateStart);

ssafAssert(
    str_contains($api,"attempted: true"),
    'Once ReplyCase POST is attempted, the result must carry an explicit attempted marker.'
);
ssafAssert(
    str_contains($update,"apiReply.attempted === true"),
    'Seller Support update must distinguish uncertain POST outcomes from pre-write channel discovery failures.'
);
ssafAssert(
    str_contains($update,"SUPPORT_REPLY_API_FAILED"),
    'A failed/uncertain ReplyCase POST must fail closed instead of falling through to a second UI write.'
);
$failedPos=strpos($update,"SUPPORT_REPLY_API_FAILED");
$composerPos=strpos($update,'ensureSupportReplyComposer(cdp)');
ssafAssert(
    $failedPos!==false && $composerPos!==false && $failedPos<$composerPos,
    'API failure handling must occur before any UI send fallback to prevent duplicate replies.'
);

echo "seller-support-reply-api-fail-closed-test: OK\n";
