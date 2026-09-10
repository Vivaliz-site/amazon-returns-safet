<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$api=(string)file_get_contents($root.'/admin/amazon-returns/api/case.php');
$js=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');
function cha(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
cha(str_contains($api,'history_summary'),'Case API must expose summarized history.');
cha(str_contains($api,"include_history"),'Raw history must be opt-in.');
cha(str_contains($api,'$includeHistory?$timeline:[]')||str_contains($api,'$includeHistory ? $timeline : []'),'Raw timeline must be omitted by default.');
cha(str_contains($js,'history_summary'),'Operational UI must consume summarized history.');
cha(str_contains($js,'total_events'),'History control must show original event count.');
echo "cockpit-history-api-contract-test: OK\n";