<?php
declare(strict_types=1);
function deepAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach(['SearchForCases','ViewCase?caseId=','searchPageSize:50','totalNumberOfResults'] as $needle){
    deepAssert(str_contains($worker,$needle),'Support case lookup must inspect Seller Central case history deeply: '.$needle);
}
$start=strpos($worker,'async function findSupportCase');
$end=$start===false?false:strpos($worker,'async function clickFrameIncludes',$start);
deepAssert($start!==false&&$end!==false,'findSupportCase must remain independently auditable.');
$body=substr($worker,(int)$start,(int)$end-(int)$start);
deepAssert(str_contains($body,'Cdp.connect()'),'Support case lookup must use an isolated CDP target so read-back does not navigate away from the submitted form.');
deepAssert(str_contains($body,'SUPPORT_CASE_LOOKUP_UNAVAILABLE'),'Case lookup failures must fail closed instead of being treated as not found.');
deepAssert(str_contains($worker,'SUPPORT_CASE_HISTORY_LIMIT'),'Deep lookup must be bounded while still covering the current account history.');
$supportStart=strpos($worker,'async function supportOpen');
$supportEnd=$supportStart===false?false:strpos($worker,'async function supportUpdate',$supportStart);
deepAssert($supportStart!==false&&$supportEnd!==false,'supportOpen must remain independently auditable.');
$support=substr($worker,(int)$supportStart,(int)$supportEnd-(int)$supportStart);
deepAssert(str_contains($support,"reason: 'SUPPORT_CASE_LOOKUP_UNAVAILABLE'"),'Pre-write case history failure must defer safely without opening a duplicate case.');
$contactStart=strpos($worker,'async function contactSupportAndReadBack');
$contactEnd=$contactStart===false?false:strpos($worker,'async function fillGeneralSupportIssue',$contactStart);
deepAssert($contactStart!==false&&$contactEnd!==false,'Support read-back function must remain independently auditable.');
$contact=substr($worker,(int)$contactStart,(int)$contactEnd-(int)$contactStart);
deepAssert(str_contains($contact,"SUPPORT_CASE_LOOKUP_UNAVAILABLE_AFTER_WRITE"),'Post-write lookup failure must be distinguished from a safe pre-write miss.');
deepAssert(str_contains($worker,'SUPPORT_CASE_TERMINAL_STATUSES'),'Duplicate suppression must distinguish active support cases from resolved historical cases.');
deepAssert(str_contains($worker,'viewCaseMetaData?.caseStatus'),'Deep lookup must read the authoritative support case status.');
deepAssert(str_contains($worker,'activeSupportStatus'),'Only active Seller Support cases may suppress a new support escalation.');
deepAssert(str_contains($worker,'{ includeTerminal = false }'),'Deep history lookup must default to active-only duplicate suppression.');
deepAssert(str_contains($worker,'supportStatusAllowed(item.status)&&relevant.test'),'Deep history lookup must preserve bounded status-aware detail inspection.');
deepAssert(str_contains($worker,'includeTerminal ? true : activeSupportStatus'),'Post-write confirmation may include terminal cases without weakening pre-write duplicate suppression.');
echo "seller-support-deep-case-readback-test: OK\n";
