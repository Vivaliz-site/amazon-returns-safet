<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/admin/amazon-returns/api/case.php');
$js=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');
$base=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit.js');
function cic(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['amazon_rma_id','merchant_rma_id','return_tracking_id','return_carrier','return_delivery_date'] as $key)cic(str_contains($api,$key),'Case API missing '.$key);
foreach(['Rastreio do pedido original','Rastreio da devolução','Transportadora da devolução','Amazon RMA','Valor esperado do ressarcimento'] as $label)cic(str_contains($js,$label),'Case detail missing '.$label);
cic(str_contains($js,'summary.decision_explanation'),'Case detail must use server decision explanation.');
cic(str_contains($js,'summary.decision_basis'),'Case detail must show factual decision basis.');
cic(str_contains($js,'function financialProgress('),'Financial recovery progress is required.');
cic(str_contains($js,"document.createElement('progress')"),'Accessible native progress element is required.');
cic(str_contains($base,'function parsedDate('),'Date parsing must distinguish zoned and SQL timestamps.');
cic(!str_contains($base,"replace(' ','T')+'Z'"),'Date formatter must not append Z blindly.');
cic(!str_contains($js,'Tentativas e resposta da Amazon'),'Conversation must replace redundant attempt summary.');
echo "cockpit-case-intelligence-contract-test: OK\n";