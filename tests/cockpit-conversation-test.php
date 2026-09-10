<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/amazon-returns/CockpitConversation.php';

function ccSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}

$seller='Pedido 702-0000000-0000001. Solicito reavaliação da decisão.';
$amazon='Analisamos seu recurso e negamos sua solicitação de reembolso.';
$timeline=[
    ['id'=>'outbox:1','occurred_at'=>'2026-09-08 10:00:00','category'=>'EXTERNAL_WRITE','source'=>'SELLER_CENTRAL','status'=>'SUCCEEDED','content'=>['action'=>'SAFE_T_APPEAL','narrative'=>$seller]],
    ['id'=>'event:2','occurred_at'=>'2026-09-08 11:00:00','category'=>'OBSERVATION','source'=>'SELLER_CENTRAL','status'=>'DENIED','content'=>['communications'=>[
        ['actor'=>'SELLER','body'=>$seller,'occurred_at'=>null,'kind'=>'APPEAL'],
        ['actor'=>'AMAZON','body'=>$amazon,'occurred_at'=>null,'kind'=>'DECISION'],
    ]]],
    ['id'=>'event:3','occurred_at'=>'2026-09-08 12:00:00','category'=>'AMAZON_RESPONSE','source'=>'GMAIL','status'=>'APPROVED','content'=>['review_excerpt'=>'O crédito será processado.','gmail_thread_id'=>'thread-3']],
    ['id'=>'outbox:4','occurred_at'=>'2026-09-08 13:00:00','category'=>'EXTERNAL_WRITE','source'=>'SELLER_CENTRAL','status'=>'SUCCEEDED','content'=>['action'=>'SELLER_SUPPORT_OPEN','narrative'=>'Solicito análise do caso pelo suporte.']],
];

$messages=SvAmazonCockpitConversation::project($timeline);
ccSame(4,count($messages),'Conversation must deduplicate seller SAFE-T readback.');
ccSame(['SELLER','AMAZON','AMAZON','SELLER'],array_column($messages,'actor'),'Actors must be preserved.');
ccSame(['SAFE_T','SAFE_T','EMAIL','SELLER_SUPPORT'],array_column($messages,'channel'),'Channels must be explicit.');ccSame(null,$messages[1]['occurred_at'],'Unknown message time must not be invented from observation time.');
ccSame('2026-09-08 11:00:00',$messages[1]['observed_at'],'Observation time must remain available separately.');
ccSame('O crédito será processado.',$messages[2]['body'],'Gmail excerpt must be visible as Amazon response.');
ccSame('thread-3',$messages[2]['thread_id'],'Email thread id must be preserved.');

echo "cockpit-conversation-test: OK\n";