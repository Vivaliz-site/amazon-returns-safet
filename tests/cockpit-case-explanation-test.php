<?php
declare(strict_types=1);
$root=dirname(__DIR__);
function cceAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$ui=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');
$api=(string)file_get_contents($root.'/admin/amazon-returns/api/case.php');
$timeline=(string)file_get_contents($root.'/includes/amazon-returns/CockpitTimeline.php');
foreach(['O que aconteceu','O que o sistema fez','O que acontece agora','Mensagens com a Amazon','Evidências','Por que o sistema decidiu isso?','Datas importantes','Valores','Ver histórico completo'] as $copy){
    cceAssert(str_contains($ui,$copy),'Case explanation UI missing '.$copy);
}
foreach(['function caseMessageItems(','decision-explanation','Fatos decisivos','Regra aplicada','Conclusão','Ainda em acompanhamento'] as $needle){
    cceAssert(str_contains($ui,$needle),'Decision explanation contract missing '.$needle);
}
cceAssert(str_contains($ui,'O conteúdo histórico dessa mensagem não foi armazenado.'),'Historical missing message body needs an explicit honest placeholder.');
cceAssert(!str_contains($ui,"text('p','MISSING_HISTORICAL_SNAPSHOT')"),'Internal narrative marker cannot reach UI.');
foreach(["'return_tracking_ids'","'return_reason'","'applied_rule'","'physical_received_at'","'last_read_back'"] as $field){
    cceAssert(str_contains($api,$field),'Case API missing detail field '.$field);
}
cceAssert(str_contains($api,"RETURN_REPORT_OBSERVED"),'Case detail must derive return reason from actual returns report events.');
cceAssert(str_contains($timeline,"'review_excerpt'"),'Timeline must preserve safe Amazon review excerpts.');
cceAssert(!str_contains($api,'new SvAmazonReturnsSpApi'),'Opening case detail cannot trigger SP-API reads.');
cceAssert(!str_contains($api,'new SvAmazonGmailApiClient'),'Opening case detail cannot trigger Gmail reads.');
echo "cockpit-case-explanation-test: OK\n";
