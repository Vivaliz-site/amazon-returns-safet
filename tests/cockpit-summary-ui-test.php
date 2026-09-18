<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$summary=(string)@file_get_contents($root.'/admin/amazon-returns/assets/cockpit-summary.js');
$operational=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');

function csuAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}

foreach(['autonomy-status','user-work','automation-work','money-headlines','money-breakdown','operational-problems','deadline-list','connector-health'] as $id){
    csuAssert(str_contains($page,'id="'.$id.'"'),'Missing dashboard region '.$id);
}
foreach(['Operando normalmente','Sistema requer atenção','Sua intervenção é necessária','Nenhuma ação sua é necessária','Sistema tratando agora','Em risco','Aguardando crédito','Em disputa','Recuperado','Últimos dados disponíveis'] as $text){
    csuAssert(str_contains($summary,$text),'Missing operator copy '.$text);
}
csuAssert(!str_contains($operational,'while(all.length<total'),'Browser must not scan every case to build the top summary.');
csuAssert(!str_contains($summary,'innerHTML'),'Summary renderer cannot use innerHTML.');
csuAssert(str_contains($page,'Pedido, NF, TBR, SAFE-T, rastreio, SKU ou ASIN'),'Search hint must include TBR.');
csuAssert(str_contains($page,'cockpit-summary.js?v='),'Summary asset must be versioned.');
foreach(['Prazo para recurso','Próxima providência automática','Data de elegibilidade'] as $label){
    csuAssert(str_contains($summary,$label),'Deadline rows must explain the business meaning: '.$label);
}

csuAssert(str_contains($page,'id="operations-detail"'),'Secondary operational telemetry must be collapsible.');
csuAssert(strpos($page,'class="panel cockpit"')<strpos($page,'id="automation-work"'),'Cases workspace must appear before background automation telemetry.');
csuAssert(str_contains($page,'class="operator-glance"'),'Top operator glance summary required.');
csuAssert(str_contains($summary,'response.status===401'),'Summary loader must detect an expired admin session explicitly.');
csuAssert(str_contains($summary,"throw new Error('SESSION_EXPIRED')"),'Summary loader must propagate the session-expired sentinel.');
csuAssert(str_contains($page,'cockpit.js?v='),'Base cockpit session recovery must be loaded with the summary module.');
echo "cockpit-summary-ui-test: OK\n";
