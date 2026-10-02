<?php
declare(strict_types=1);
function chatEnterAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
foreach(['async function sendHillChatMessage','Write here and press Enter','Input.dispatchKeyEvent',"key: 'Enter'",'sendHillChatMessage(chat, narrative)','sendHillChatMessage(chat, reply)'] as $needle){chatEnterAssert(str_contains($worker,$needle),'Seller Support chat Enter contract missing: '.$needle);}
echo "seller-support-chat-enter-contract-test: OK\n";
