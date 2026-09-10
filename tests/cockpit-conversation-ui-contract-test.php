<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$operational=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');
$file=$root.'/admin/amazon-returns/assets/cockpit-conversation.js';
if(!is_file($file))throw new RuntimeException('cockpit-conversation.js missing');
$js=(string)file_get_contents($file);
function ccui(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
ccui(str_contains($page,'cockpit-conversation.js'),'Conversation renderer must load on cockpit page.');
ccui(str_contains($operational,'renderOperatorConversation(data.conversation||[])'),'Case detail must render API conversation.');
foreach(['Conversa com a Amazon','Nossa mensagem','Amazon','SAFE-T','E-mail','Suporte','Ver mensagem completa'] as $label){
    ccui(str_contains($js,$label),'Conversation UI missing '.$label);
}
ccui(str_contains($js,"setAttribute('aria-pressed'"),'Conversation channel filters need pressed state.');
ccui(str_contains($js,'observed_at'),'Observed time fallback must be distinct from message time.');
ccui(!str_contains($js,'innerHTML'),'Conversation text must never use innerHTML.');
echo "cockpit-conversation-ui-contract-test: OK\n";