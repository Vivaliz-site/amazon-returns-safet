<?php
declare(strict_types=1);
function ssuiAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(dirname(__DIR__).'/scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach([
    'FBA Returns Reimbursement',
    'Continue',
    'Request Reimbursement for an Order',
    'Contact an associate',
] as $label){
    ssuiAssert(str_contains($worker,$label),'Seller Support worker must recognize current English UI label: '.$label);
}
foreach([
    'Reembolso de devoluções com FBA - Logística da Amazon',
    'Continuar',
    'Solicitar reembolso para um pedido',
    'Entre em contato com um associado',
] as $label){
    ssuiAssert(str_contains($worker,$label),'Seller Support worker must preserve Portuguese UI fallback: '.$label);
}
ssuiAssert(str_contains($worker,'FBA_RETURNS_REIMBURSEMENT'),'Seller Support worker must route classic FBA jobs explicitly.');
ssuiAssert(str_contains($worker,'GENERAL_ORDER_SUPPORT'),'Seller Support worker must distinguish non-FBA/general support jobs.');
ssuiAssert(str_contains($worker,'SUPPORT_ROUTE_UNSUPPORTED'),'Seller Support worker must fail closed for an unknown support route before external write.');
ssuiAssert(str_contains($worker,'SUPPORT_FBA_CARD_MISSING'),'Seller Support worker must still fail closed when no supported FBA card is present.');
echo "seller-support-current-ui-contract-test: OK\n";
