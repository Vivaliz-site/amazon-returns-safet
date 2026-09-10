<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/amazon-returns/CockpitHistory.php';
function chSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}

$items=[];
for($i=0;$i<100;$i++)$items[]=[
    'id'=>'event:'.$i,'occurred_at'=>'2026-09-08 10:'.str_pad((string)($i%60),2,'0',STR_PAD_LEFT).':00',
    'category'=>'OBSERVATION','title'=>'Pedido sincronizado','source'=>'SP_API','status'=>'ORDER_SYNCED','content'=>[],
];
$items[]=['id'=>'event:next-day','occurred_at'=>'2026-09-09 09:00:00','category'=>'OBSERVATION','title'=>'Pedido sincronizado','source'=>'SP_API','status'=>'ORDER_SYNCED','content'=>[]];
$items[]=['id'=>'outbox:1','occurred_at'=>'2026-09-08 11:00:00','category'=>'EXTERNAL_WRITE','title'=>'Recurso SAFE-T','source'=>'SELLER_CENTRAL','status'=>'SUCCEEDED','content'=>['action'=>'SAFE_T_APPEAL']];
$items[]=['id'=>'event:error','occurred_at'=>'2026-09-08 12:00:00','category'=>'ERROR','title'=>'Falha de processamento','source'=>'SYSTEM','status'=>'FAILED','content'=>[]];

$summary=SvAmazonCockpitHistory::summarize($items);
chSame(103,$summary['total_events'],'Total raw event count must remain visible.');
chSame(4,$summary['visible_items'],'Repeated observations on same day must collapse.');
chSame(99,$summary['grouped_events'],'Grouped count must explain compression.');
$group=array_values(array_filter($summary['items'],fn(array $x):bool=>($x['repeat_count']??0)===100))[0]??null;
chSame('2026-09-08 10:00:00',$group['first_at']??null,'Group needs first occurrence.');
chSame('2026-09-08 10:59:00',$group['last_at']??null,'Group needs last occurrence.');
echo "cockpit-history-test: OK\n";