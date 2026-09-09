<?php
declare(strict_types=1);
function ssuiAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(dirname(__DIR__).'/scripts/amazon-returns/seller-central-bridge-worker.mjs');
ssuiAssert(str_contains($worker,"FBA Returns Reimbursement"),
    'Seller Support worker must recognize the current Seller Central FBA reimbursement card.');
ssuiAssert(str_contains($worker,"Reembolso de devoluções com FBA - Logística da Amazon"),
    'Seller Support worker must preserve the legacy Portuguese FBA card fallback.');
ssuiAssert(str_contains($worker,"SUPPORT_FBA_CARD_MISSING"),
    'Seller Support worker must still fail closed when neither supported card is present.');
echo "seller-support-current-ui-contract-test: OK\n";
