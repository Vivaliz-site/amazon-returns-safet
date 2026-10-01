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
    str_contains($update,"apiReply.status === 'HTTP_ERROR'"),
    'A definite non-2xx ReplyCase response must have an explicit recovery branch.'
);
ssafAssert(
    str_contains($update,"waitForSupportCaseText(cdp, caseId, narrative.slice(0, 240))"),
    'HTTP-error recovery must read back the case before any second write to prevent duplicate replies.'
);
ssafAssert(
    str_contains($update,"SUPPORT_REPLY_HTTP_ERROR_BUT_READ_BACK_CONFIRMED"),
    'If the failed HTTP response nevertheless produced the reply, read-back must close the operation as accepted.'
);
ssafAssert(
    str_contains($update,"SUPPORT_REPLY_API_FAILED"),
    'Uncertain ReplyCase outcomes must remain fail-closed.'
);
$httpPos=strpos($update,"apiReply.status === 'HTTP_ERROR'");
$composerPos=strpos($update,'ensureSupportReplyComposer(cdp)');
ssafAssert(
    $httpPos!==false && $composerPos!==false && $httpPos<$composerPos,
    'The definite HTTP-error recovery/read-back path must run before the UI composer fallback.'
);

echo "seller-support-reply-api-fail-closed-test: OK\n";
