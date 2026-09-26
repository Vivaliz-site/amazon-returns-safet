<?php
declare(strict_types=1);

function ssapiAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$channelsStart=strpos($worker,'async function supportReplyChannels');
$apiStart=strpos($worker,'async function submitSupportEmailReplyApi');
$updateStart=strpos($worker,'async function supportUpdate');
ssapiAssert($channelsStart!==false && $apiStart!==false,'Seller Support updates must expose channel discovery and a direct authenticated Email ReplyCase API path.');
ssapiAssert($updateStart!==false,'Seller Support update flow must remain auditable.');
$channels=substr($worker,(int)$channelsStart,(int)$apiStart-(int)$channelsStart);
$api=substr($worker,(int)$apiStart,(int)$updateStart-(int)$apiStart);
$update=substr($worker,(int)$updateStart);

ssapiAssert(
    str_contains($channels,"/hill/hillservice/mons-api/GetReplyChannels?caseId="),
    'Seller Support channel discovery must use the authenticated GetReplyChannels endpoint.'
);
foreach([
    "/hill/hillservice/mons-api/ReplyCase",
    "channelType:'Email'",
    "formFields:{describeIssue:narrative}",
    "emailData:{attachments:[]}",
    "credentials:'include'",
    "'client-id':'viewCase'",
    "'enforce-waf-captcha-for-bot':'true'",
    "waitForSupportCaseText(cdp, caseId, narrative.slice(0, 240))",
] as $needle){
    ssapiAssert(str_contains($api,$needle),'Direct Seller Support Email API contract missing: '.$needle);
}
ssapiAssert(
    strpos($update,'submitSupportEmailReplyApi(cdp, caseId, narrative)') < strpos($update,'ensureSupportReplyComposer(cdp)'),
    'Seller Support update must prefer the authenticated ReplyCase API before UI-composer fallback.'
);

echo "seller-support-reply-api-test: OK\n";
