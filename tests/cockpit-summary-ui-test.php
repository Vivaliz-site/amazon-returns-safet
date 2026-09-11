<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$summary=(string)@file_get_contents($root.'/admin/amazon-returns/assets/cockpit-summary.js');
$bootstrap=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational-bootstrap.js');

function csuAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

foreach(['autonomy-status','user-work','automation-work','money-headlines','money-breakdown','operational-problems','deadline-list','connector-health'] as $id){
    csuAssert(str_contains($page,'id="'.$id.'"'),'Missing dashboard region '.$id);
}
foreach(['Operando normalmente','Sistema requer atenção','Sua intervenção é necessária','Nenhuma ação sua é necessária','Sistema tratando agora','Em risco','Aguardando crédito','Em disputa','Recuperado','Últimos dados disponíveis'] as $text){
    csuAssert(str_contains($summary,$text),'Missing operator copy '.$text);
}
csuAssert(!str_contains($bootstrap,'while(all.length<total'),'Browser must not scan every case to build the top summary.');
csuAssert(!str_contains($summary,'innerHTML'),'Summary renderer cannot use innerHTML.');
csuAssert(str_contains($page,'Pedido, NF, TBR, SAFE-T, rastreio, SKU ou ASIN'),'Search hint must include TBR.');
csuAssert(str_contains($page,'cockpit-summary.js?v='),'Summary asset must be versioned.');
foreach(['Prazo para recurso','Próxima providência automática','Data de elegibilidade'] as $label){
    csuAssert(str_contains($summary,$label),'Deadline rows must explain the business meaning: '.$label);
}

echo "cockpit-summary-ui-test: OK\n";
