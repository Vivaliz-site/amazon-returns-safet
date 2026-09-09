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
] as $needle){
    sgrAssert(str_contains($worker,$needle),'General Seller Support worker must recognize observed live UI/route contract: '.$needle);
}
sgrAssert(str_contains($worker,"waitFrameHas(cdp, 'FBA Returns Reimbursement'"),'FBA card must be awaited because the live Help frame loads asynchronously.');
sgrAssert(str_contains($worker,"waitFrameHas(cdp, 'My issue is not listed'"),'General support route must wait for the live Help frame before clicking.');
sgrAssert(str_contains($worker,'SUPPORT_GENERAL_PRODUCT_IDENTITY_REQUIRED'),'General Seller Support must fail closed when ASIN/SKU identity is unavailable.');
echo "seller-support-general-route-contract-test: OK\n";
