<?php
declare(strict_types=1);

function sspfiAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');

$scanStart=strpos($worker,'async function scanSupportCaseHistory');
$scanEnd=$scanStart===false?false:strpos($worker,'async function findSupportCase',$scanStart);
sspfiAssert($scanStart!==false && $scanEnd!==false,'Support case history scan must remain auditable.');
$scan=substr($worker,(int)$scanStart,(int)$scanEnd-(int)$scanStart);
sspfiAssert(
    str_contains($scan,'excludeCaseIds'),
    'Historical Seller Support lookup must accept case IDs that existed before a write.'
);
sspfiAssert(
    str_contains($scan,'excluded.has(caseId)'),
    'Historical Seller Support lookup must never return a pre-write case ID as post-write confirmation.'
);

$contactStart=strpos($worker,'async function contactSupportAndReadBack');
$contactEnd=$contactStart===false?false:strpos($worker,'async function fillGeneralSupportIssue',$contactStart);
sspfiAssert($contactStart!==false && $contactEnd!==false,'Email/contact readback path must remain auditable.');
$contact=substr($worker,(int)$contactStart,(int)$contactEnd-(int)$contactStart);
sspfiAssert(
    str_contains($contact,'preWriteCaseIds'),
    'Email/contact readback must preserve the case IDs that existed before submission.'
);
sspfiAssert(
    str_contains($contact,"if (caseId && (preWriteCaseIds.has(caseId) || !(await supportCaseMatchesJob(cdp, job, caseId)))) caseId = '';"),
    'Current-page readback must reject a pre-existing case ID.'
);
sspfiAssert(
    str_contains($contact,'excludeCaseIds: [...preWriteCaseIds]'),
    'Fallback history readback must exclude every pre-write case ID.'
);

$directStart=strpos($worker,'async function submitDirectSupportCaseAndReadBack');
$directEnd=$directStart===false?false:strpos($worker,'async function openGeneralSupportRoute',$directStart);
sspfiAssert($directStart!==false && $directEnd!==false,'Direct case creation readback path must remain auditable.');
$direct=substr($worker,(int)$directStart,(int)$directEnd-(int)$directStart);
sspfiAssert(
    str_contains($direct,'preWriteCaseIds'),
    'Direct case creation must preserve the case IDs that existed before submission.'
);
sspfiAssert(
    str_contains($direct,"if (caseId && (preWriteCaseIds.has(caseId) || !(await supportCaseMatchesJob(cdp, job, caseId)))) caseId = '';"),
    'Direct-create current-page readback must reject a pre-existing case ID.'
);
sspfiAssert(
    str_contains($direct,'excludeCaseIds: [...preWriteCaseIds]'),
    'Direct-create history readback must exclude every pre-write case ID.'
);

echo "seller-support-postwrite-fresh-id-test: OK\n";
