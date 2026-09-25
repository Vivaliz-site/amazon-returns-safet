<?php
declare(strict_types=1);

$daemon=__DIR__.'/../workers/amazon-returns/daemon.php';
require_once $daemon;

function ddacAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function ddacSame(mixed $want,mixed $got,string $message):void{if($want!==$got)throw new RuntimeException($message.' expected='.var_export($want,true).' got='.var_export($got,true));}

$rows=[];
for($i=1;$i<=19;$i++){
    $rows[]=[
        'case_id'=>$i,
        'order_id'=>sprintf('702-%07d-%07d',$i,$i),
        'state'=>'CREDIT_PENDING',
        'action'=>'WAIT',
        'reason'=>'CLASSIC_FBA_D45_PENDING',
        'event_counts'=>['ORDER_SYNCED'=>1,'FINANCIAL_RECONCILIATION_CHECKED'=>2],
    ];
}
$result=[
    'at'=>'2026-09-25T01:30:00+00:00',
    'tenant_id'=>1,
    'amazon_connection_id'=>1,
    'results'=>['scheduler'=>['decision_audit'=>$rows]],
];
$chunks=sv_amazon_returns_decision_audit_chunks($result);
ddacSame(3,count($chunks),'19 rows must be split into bounded 8-row journal chunks.');
ddacSame(8,count($chunks[0]['rows']??[]),'first chunk size');
ddacSame(8,count($chunks[1]['rows']??[]),'second chunk size');
ddacSame(3,count($chunks[2]['rows']??[]),'last chunk size');
ddacSame(1,$chunks[0]['chunk']??null,'first chunk index');
ddacSame(3,$chunks[0]['chunks']??null,'chunk total');
ddacSame('decision_audit_chunk',$chunks[0]['event']??null,'event name');
ddacSame('2026-09-25T01:30:00+00:00',$chunks[0]['at']??null,'cycle timestamp');
ddacSame(1,$chunks[0]['tenant_id']??null,'tenant id');
ddacSame(1,$chunks[0]['amazon_connection_id']??null,'connection id');

$flat=[];
foreach($chunks as $chunk){
    foreach($chunk['rows']??[] as $row)$flat[]=$row;
    $encoded=json_encode($chunk,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    ddacAssert(strlen($encoded)<48000,'Each decision audit chunk must stay below the journald truncation boundary.');
}
ddacSame(19,count($flat),'Chunking must preserve every decision row exactly once.');
ddacSame(19,count(array_unique(array_column($flat,'case_id'))),'Chunking must not duplicate case rows.');

ddacSame([],sv_amazon_returns_decision_audit_chunks(['results'=>[]]),'No scheduler audit must emit no chunks.');
echo "daemon-decision-audit-chunk-test: OK\n";
