<?php
declare(strict_types=1);
function ssrrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach([
  "Input.dispatchMouseEvent",
  "SUPPORT_CASE_OPENED_VIA_CHAT",
  "SUPPORT_CHAT_START_MISSING",
  "SUPPORT_CASE_NOT_REOPENABLE",
  "answered cases cannot be reopened after 5 days with no activity",
] as $needle){ssrrAssert(str_contains($worker,$needle),'Runtime Seller Support remediation missing: '.$needle);}
ssrrAssert(str_contains($worker,"DOM.scrollIntoViewIfNeeded"),'Trusted iframe clicks must use the browser protocol to reveal off-screen controls before dispatching mouse events.');
ssrrAssert(str_contains($worker,"DOM.getBoxModel"),'Trusted iframe clicks must derive the physical click point from the browser box model after scrolling.');
$contactStart=strpos($worker,'async function contactSupportAndReadBack');
$contactEnd=strpos($worker,'async function fillGeneralSupportIssue',$contactStart);
$contact=substr($worker,$contactStart,$contactEnd-$contactStart);
ssrrAssert(str_contains($contact,'channel = await clickHillChat(cdp)'),'Live Phone/Chat-only Hill form must fall back to trusted Chat.');
ssrrAssert(str_contains($contact,"findSupportCase(cdp, job, { includeTerminal: true })"),'Chat/email writes require authoritative case-ID readback before success.');
ssrrAssert(!str_contains($contact,'SUPPORT_CHAT_COMPLETION_NOT_IMPLEMENTED'),'Observed Chat-only Hill form can no longer be left unimplemented.');
$fbaStart=strpos($worker,'async function supportOpen');
$fba=substr($worker,$fbaStart);
ssrrAssert(str_contains($fba,"clickFirstFrameTextWhenReady(cdp, ['FBA related','A-to-z Claims'], 15000)"),'FBA route must advance the observed post-contact associate category.');
ssrrAssert(str_contains($fba,"frameHas(cdp, 'Having issues with your order?')"),'FBA route must advance the observed reimbursement troubleshooter handoff.');
ssrrAssert(str_contains($fba,"frameHas(cdp, 'Create a case')"),'FBA route must accept the observed direct case form instead of waiting for Hill forever.');
ssrrAssert(str_contains($fba,'const flowDeadline = Date.now() + 180000;'),'FBA route must allow the observed slow Seller Central transition to reach Contact an associate.');
ssrrAssert(str_contains($fba,'const graceDeadline = Date.now() + 45000;'),'FBA route must process an actionable contact control that appears at the transition boundary.');
ssrrAssert(str_contains($fba,"clickFrameButtonTrustedByText('Continue')"),'FBA post-contact Continue transition must use a trusted CDP mouse click before DOM fallback.');
$contactPos=strpos($fba,"frameButtonReadyByText('Contact an associate')");
$requestPos=strpos($fba,"frameHas(cdp, 'Request Reimbursement for an Order')");
ssrrAssert($contactPos!==false && $requestPos!==false && $contactPos<$requestPos,'Actionable Seller Support contact must take priority over repeating the reimbursement troubleshooter.');
ssrrAssert(str_contains($worker,'async frameButtonReadyByText(label)'),'Seller Support must distinguish an actionable button from stale body text.');
ssrrAssert(str_contains($worker,'async frameOptionSelectedByText(label)'),'Seller Support must recognize a selected contact option while waiting for the next actionable control.');
ssrrAssert(str_contains($fba,'SUPPORT_CONTACT_SELECTED_CONTINUE_MISSING'),'FBA recovery must explicitly advance the selected contact option through Continue.');
ssrrAssert(str_contains($fba,'SUPPORT_CONTACT_ADDITIONAL_INFO_NOT_WRITABLE'),'FBA recovery must fill the observed required additional-information field before Continue.');
ssrrAssert(str_contains($worker,'async fillFrameTextareaTrusted(selector, value)'),'Required Seller Support textarea input must use trusted browser input rather than synthetic DOM-only events.');
ssrrAssert(str_contains($fba,'if (!additionalInfoReady)'),'FBA recovery must wait for the asynchronous additional-information field before attempting Continue.');
ssrrAssert(str_contains($fba,"for (const contactLabel of ['Contact an associate','Entre em contato com um associado'])"),'FBA boundary grace must use only trusted observed contact labels.');
echo "seller-support-runtime-remediation-test: OK
";
