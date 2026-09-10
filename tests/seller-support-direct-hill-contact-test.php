<?php
declare(strict_types=1);
function dhAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach([
    'Having issues with your order?',
    'hillContactReady',
    'submitHillEmail',
    'kat-tabs',
    'tab-id="Email"',
    'SUPPORT_EMAIL_ADDRESS_MISSING',
    'SUPPORT_CASE_OPENED_VIA_EMAIL',
] as $needle){
    dhAssert(str_contains($worker,$needle),'Seller Support direct Hill/email contract missing: '.$needle);
}
dhAssert(str_contains($worker,'if (await hillContactReady(cdp)) return null;'),'General route must accept a direct Hill contact form without forcing ASIN/SKU fields.');
dhAssert(str_contains($worker,"['Chat now','Conversar agora','Iniciar chat']"),'Chat remains the preferred live channel when available.');
dhAssert(str_contains($worker,"label=(send.getAttribute('label')||send.innerText||'').trim();if(label!=='Send'"),'Email fallback must click only the enabled Hill Send action.');
echo "seller-support-direct-hill-contact-test: OK\n";
