<?php
declare(strict_types=1);
function cuAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$cssFile=$root.'/admin/amazon-returns/assets/cockpit.css';
$jsFile=$root.'/admin/amazon-returns/assets/cockpit.js';
$operationalCssFile=$root.'/admin/amazon-returns/assets/cockpit-operational.css';
$operationalJsFile=$root.'/admin/amazon-returns/assets/cockpit-operational.js';
$bootstrapFile=$root.'/admin/amazon-returns/assets/cockpit-operational-bootstrap.js';
$summaryFile=$root.'/admin/amazon-returns/assets/cockpit-summary.js';
if(!is_file($cssFile))throw new RuntimeException('cockpit.css missing');
if(!is_file($jsFile))throw new RuntimeException('cockpit.js missing');
if(!is_file($operationalCssFile))throw new RuntimeException('cockpit-operational.css missing');
if(!is_file($operationalJsFile))throw new RuntimeException('cockpit-operational.js missing');
if(!is_file($bootstrapFile))throw new RuntimeException('cockpit-operational-bootstrap.js missing');
if(!is_file($summaryFile))throw new RuntimeException('cockpit-summary.js missing');
$css=(string)file_get_contents($cssFile);$js=(string)file_get_contents($jsFile);
$operationalCss=(string)file_get_contents($operationalCssFile);$operationalJs=(string)file_get_contents($operationalJsFile);$bootstrap=(string)file_get_contents($bootstrapFile);$summary=(string)file_get_contents($summaryFile);
foreach(['viewport','cockpit.css','cockpit.js','cockpit-operational.css','cockpit-operational.js','cockpit-operational-bootstrap.js','Casos','Revisões','case-list','case-detail'] as $needle)cuAssert(str_contains($page,$needle),'Cockpit page missing '.$needle);
foreach(['Pesquisar','Situação','Próxima ação','Tipo de logística','Recebimento','Prazo'] as $label)cuAssert(str_contains($page,$label),'Cockpit filter label missing '.$label);
foreach(['Todos','Precisa da minha atenção','Sistema tratando','Concluídos'] as $label)cuAssert(str_contains($page,$label),'Quick filter missing '.$label);
cuAssert(str_contains($page,'data-view="cases"'),'Cases tab required.');
cuAssert(str_contains($page,'data-view="reviews"'),'Reviews tab required.');
cuAssert(str_contains($page,'id="review-count"'),'Visible pending-review count target required.');
cuAssert(str_contains($page,'id="review-alert"'),'Visible pending-review alert required.');
foreach(['autonomy-status','user-work','automation-work','money-headlines','operational-problems','deadline-list','connector-health'] as $id)cuAssert(str_contains($page,'id="'.$id.'"'),'Operational dashboard target required: '.$id);
cuAssert(str_contains($summary,'human_action_count'),'Summary renderer must use explicit human-action count.');
cuAssert(!str_contains($js,'innerHTML'),'API text must not be injected as HTML.');
cuAssert(!str_contains($operationalJs,'innerHTML'),'Operational UI must not inject API text as HTML.');
cuAssert(!str_contains($bootstrap,'innerHTML'),'Operational bootstrap must not inject API text as HTML.');
foreach(['textContent','createElement','loadCases','loadReviews','openCase','renderTimeline','renderMessageThread','humanReviewSummary'] as $needle)cuAssert(str_contains($js,$needle),'Cockpit JS missing '.$needle);
cuAssert(str_contains($js,"Intl.NumberFormat('pt-BR'"),'BRL formatter required.');
cuAssert(str_contains($js,"toLocaleString('pt-BR'"),'pt-BR date formatter required.');
cuAssert(str_contains($css,'@media(max-width:600px)'),'Mobile layout required.');
cuAssert(str_contains($css,'min-height:44px'),'Mobile action targets must be at least 44px.');
cuAssert(!str_contains($css,'overflow-x:auto'),'Cockpit must not rely on page-level horizontal scrolling.');
foreach(['operatorStatus','operatorResponsibility','operatorNextStep','operatorAlert','condenseTimeline','renderOperationalCase','renderOperationalList'] as $needle)cuAssert(str_contains($operationalJs,$needle),'Operational cockpit missing '.$needle);
foreach(['operationalBucket','if(state.view===\'cases\')loadCases()'] as $needle)cuAssert(str_contains($bootstrap.$operationalJs,$needle),'Operational bootstrap missing '.$needle);
cuAssert(str_contains($summary,'AmazonReturnsSummary'),'Dedicated summary module must own top dashboard rendering.');
foreach(['Nenhuma ação sua é necessária','Sua decisão é necessária','Aguardando resposta da Amazon','Aguardando crédito da Amazon','Providência automática atrasada','Data do pedido','Reembolso concedido ao cliente','Débito na conta da loja','Saldo ainda a recuperar','NF de venda','Última verificação','Próxima providência','Ver histórico completo','Pedido sincronizado','Movimentação financeira identificada'] as $needle)cuAssert(str_contains($operationalJs.$bootstrap,$needle),'Operational copy missing '.$needle);
foreach(['case-row-id','case-row-status','case-row-finance','case-row-responsibility','case-summary','case-flow','case-financial','case-evidence','case-history-toggle'] as $needle)cuAssert(str_contains($operationalCss.$operationalJs,$needle),'Operational cockpit structure missing '.$needle);
cuAssert(!str_contains($operationalJs,"return 'Aguardar';"),'Generic standalone waiting label is prohibited.');

// Optional operational facts must never be rendered as false zeroes or meaningless fallbacks.
cuAssert(str_contains($operationalJs,"const decisionReason=c.current_reason?reasonLabel(c.current_reason):"),'Missing decision reason must use a human fallback instead of an em dash.');
cuAssert(str_contains($operationalJs,"x!=='Informação não disponível'"),'Unknown evidence source labels must be omitted.');
cuAssert(str_contains($operationalJs,"Number(c.refund_amount)>0?brl(c.refund_amount):null"),'Unknown customer refund amount must not be rendered as R$ 0,00.');

// Internal API values are preserved in form values, but every visible label is humanized.
foreach([
    'value="SAFE_T_DENIED">SAFE-T negado<',
    'value="SAFE_T_APPEAL">Recorrer no SAFE-T<',
    'value="DELIVERY_BY_AMAZON">Entrega pela Amazon<',
    'value="NOT_RECEIVED">Não recebido<',
    'value="PROMISED_DATE">Data prometida pela Amazon<',
] as $needle) cuAssert(str_contains($page,$needle),'Portuguese select label missing: '.$needle);
foreach(['const actionLabels=','const stateLabels=','const reasonLabels=','const physicalLabels=','const programLabels=','const statusLabels=','function enumLabel(','function humanText(','function friendlyError('] as $needle)cuAssert(str_contains($js,$needle),'Central Portuguese humanizer missing '.$needle);
foreach([
    'actionLabel(c.current_action)',
    'stateLabel(c.state)',
    'physicalLabel(c.physical_status)',
    'reasonLabel(r.reason)',
    'statusLabel(r.status)',
    'actionLabel(suggestion.action)',
    'humanReviewSummary(j.review,ctx,j.case)',
] as $needle) cuAssert(str_contains($js,$needle),'Operator data is still not humanized through '.$needle);
foreach([
    "REFUND_DETECTED:'Reembolso ao cliente identificado'",
    "SAFE_T_APPROVED:'Ressarcimento aprovado'",
    "IN_TRANSIT:'Devolução em transporte'",
    "UNKNOWN:'Tipo de logística não identificado'",
    "return 'Informação não disponível'",
] as $needle) cuAssert(str_contains($js,$needle),'Portuguese fallback/state coverage missing: '.$needle);
$rawSnippets=[
    <<<'RAW'
`${c.state||'—'} · ${c.physical_status||'—'}`
RAW,
    <<<'RAW'
`${brl(c.outstanding_amount)} · ${c.current_action||'WAIT'}`
RAW,
    <<<'RAW'
`Caso ${r.case_id} · ${r.reason||'—'}`
RAW,
    'JSON.stringify(ctx.facts)',
    'localizedJson(ctx.facts)',
];
foreach($rawSnippets as $raw) cuAssert(!str_contains($js,$raw),'Raw internal enum/JSON must not be shown to the operator.');

echo "cockpit-ui-contract-test: OK\n";