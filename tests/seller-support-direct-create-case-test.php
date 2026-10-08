<?php
declare(strict_types=1);
function dcAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach(['async function fillDirectSupportCaseDetails','async function waitForDirectSupportCaseDetails','async fillFrameInputTrustedByLabel','Provide details about the order reimbursement error','Input.insertText','async clickFrameButtonTrustedByText','Create a case','SUPPORT_CASE_OPENED_DIRECTLY'] as $needle){
    dcAssert(str_contains($worker,$needle),'Direct Seller Support case route must implement observed live contract: '.$needle);
}
dcAssert(substr_count($worker,'async function submitDirectSupportCaseAndReadBack(cdp, job, narrative)')===1,'Direct support submit/read-back helper must be declared exactly once so the worker parses.');
$start=strpos($worker,'async function submitDirectSupportCaseAndReadBack');
$end=$start===false?false:strpos($worker,'async function openGeneralSupportRoute',$start);
dcAssert($start!==false&&$end!==false,'Direct support submit/read-back helper must remain independently auditable.');
$body=substr($worker,(int)$start,(int)$end-(int)$start);
$fillStart=strpos($worker,'async function fillDirectSupportCaseDetails');
$fillEnd=$fillStart===false?false:strpos($worker,'async function waitForDirectSupportCaseDetails',$fillStart);
dcAssert($fillStart!==false&&$fillEnd!==false,'Direct support field helper must remain independently auditable.');
$fillBody=substr($worker,(int)$fillStart,(int)$fillEnd-(int)$fillStart);
dcAssert(str_contains($fillBody,'fillFrameInputTrustedByLabel'),'The observed controlled KAT input must be filled through trusted CDP input.');
dcAssert(!str_contains($fillBody,'Object.getOwnPropertyDescriptor'),'Direct support detail must not use the synthetic DOM setter that the live KAT input resets.');
dcAssert(str_contains($body,"waitForDirectSupportCaseDetails(cdp, narrative, 15000)"),'Direct case submission must wait for the asynchronously rendered required detail field.');
dcAssert(str_contains($body,"clickFrameButtonTrustedByText('Create a case')"),'Final Create a case action must use trusted CDP pointer input.');
dcAssert(str_contains($body,'currentSupportCaseId(cdp)'),'Direct case creation must read the resulting case ID from the live UI first.');
dcAssert(str_contains($body,'findSupportCase(cdp, job, { includeTerminal: true, excludeCaseIds: [...preWriteCaseIds] })'),'Direct case creation must reconcile Seller Central case history, including instantly resolved cases, without reusing a pre-write case ID.');
dcAssert(str_contains($body,"bridgeResult('UI_DRIFT', { reason: 'SUPPORT_WRITE_WITHOUT_READBACK_ID', submitted: true, retry_safe: true"),'After Create a case is clicked, missing read-back ID must be classified as submitted-but-unreconciled, never as a definitely unsubmitted failure.');
dcAssert(str_contains($body,"reason: 'SUPPORT_CASE_OPENED_DIRECTLY'"),'Direct case creation succeeds only with an auditable external case ID.');
dcAssert(str_contains($body,'waitForSupportCaseText(cdp, caseId, narrative.slice(0, 240))'),'Direct case creation must verify the exact submitted narrative through authoritative ViewCase readback before delivery is accepted.');
dcAssert(str_contains($body,"SUPPORT_CASE_CREATED_WITHOUT_TEXT_READBACK"),'Direct case creation without narrative readback must remain submitted-but-unconfirmed instead of becoming delivery proof.');
echo "seller-support-direct-create-case-test: OK\n";
