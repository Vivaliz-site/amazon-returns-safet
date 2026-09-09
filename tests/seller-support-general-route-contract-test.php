<?php
declare(strict_types=1);
function sgrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(dirname(__DIR__).'/scripts/amazon-returns/seller-central-bridge-worker.mjs');

sgrAssert(!str_contains($worker,'SUPPORT_GENERAL_ROUTE_NOT_IMPLEMENTED'),'GENERAL_ORDER_SUPPORT must be implemented rather than deferred as UI drift.');
foreach([
    'APPROVED_PARTIAL_REIMBURSEMENT_SUPPORT_RECOVERY',
    'My issue is not listed',
    'Use original text',
    'A-to-z Claims',
    'Enter ASIN',
    'Enter SKU',
    'spl-hill-form',
    'Chat now',
] as $needle){
    sgrAssert(str_contains($worker,$needle),'General Seller Support worker must recognize observed live UI/route contract: '.$needle);
}
sgrAssert(str_contains($worker,"waitFrameHas(cdp, 'FBA Returns Reimbursement'"),'FBA card must be awaited because the live Help frame loads asynchronously.');
sgrAssert(str_contains($worker,"waitFrameHas(cdp, 'My issue is not listed'"),'General support route must wait for the live Help frame before clicking.');
sgrAssert(str_contains($worker,'SUPPORT_GENERAL_PRODUCT_IDENTITY_REQUIRED'),'General Seller Support must fail closed when ASIN/SKU identity is unavailable.');
sgrAssert(!str_contains($worker,"if (await cdp.clickFrameText(label))"),'Support step helper must skip stale disabled duplicate buttons and click an enabled exact match.');

$genericStart=strpos($worker,'async function openGeneralSupportRoute');
$genericEnd=$genericStart===false?false:strpos($worker,'async function supportOpen',$genericStart);
sgrAssert($genericStart!==false && $genericEnd!==false,'General support route function must remain independently auditable.');
$generic=substr($worker,(int)$genericStart,(int)$genericEnd-(int)$genericStart);
foreach([
    'kat-input[placeholder*="112-"]',
    'Request Reimbursement for an Order',
    'Check FBA order reimbursement status',
    'FBA related',
    'SUPPORT_GENERAL_TROUBLESHOOTER_EXHAUSTED',
] as $needle){
    sgrAssert(str_contains($generic,$needle),'General Seller Support must advance the observed suggested-tool flow before associate contact: '.$needle);
}
sgrAssert(str_contains($worker,'async function clickFirstFrameTextWhenReady'),'Dynamic associate categories must be polled until an enabled live category exists.');
sgrAssert(str_contains($generic,"clickFirstFrameTextWhenReady(cdp, ['A-to-z Claims','Check FBA order reimbursement status','FBA related']"),'General Seller Support must support the observed dynamic category variants without choosing stale disabled duplicates.');

echo "seller-support-general-route-contract-test: OK\n";
