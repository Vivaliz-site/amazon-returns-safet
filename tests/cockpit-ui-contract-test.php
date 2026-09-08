<?php
declare(strict_types=1);
function cuAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$cssFile=$root.'/admin/amazon-returns/assets/cockpit.css';
$jsFile=$root.'/admin/amazon-returns/assets/cockpit.js';
if(!is_file($cssFile))throw new RuntimeException('cockpit.css missing');
if(!is_file($jsFile))throw new RuntimeException('cockpit.js missing');
$css=(string)file_get_contents($cssFile);$js=(string)file_get_contents($jsFile);
foreach(['viewport','cockpit.css','cockpit.js','Casos','Revisões','case-list','case-detail'] as $needle)cuAssert(str_contains($page,$needle),'Cockpit page missing '.$needle);
foreach(['Pesquisar','Situação','Próxima ação','Tipo de logística','Recebimento','Prazo'] as $label)cuAssert(str_contains($page,$label),'Cockpit filter label missing '.$label);
cuAssert(str_contains($page,'data-view="cases"'),'Cases tab required.');
cuAssert(str_contains($page,'data-view="reviews"'),'Reviews tab required.');
cuAssert(str_contains($page,'id="review-count"'),'Visible pending-review count target required.');
cuAssert(str_contains($page,'id="review-alert"'),'Visible pending-review alert required.');
cuAssert(str_contains($js,'pending_reviews'),'Cockpit JS must render pending review count from summary.');
cuAssert(!str_contains($js,'innerHTML'),'API text must not be injected as HTML.');
foreach(['textContent','createElement','loadCases','loadReviews','openCase','renderTimeline','renderMessageThread','humanReviewSummary'] as $needle)cuAssert(str_contains($js,$needle),'Cockpit JS missing '.$needle);
cuAssert(str_contains($js,"Intl.NumberFormat('pt-BR'"),'BRL formatter required.');
cuAssert(str_contains($js,"toLocaleString('pt-BR'"),'pt-BR date formatter required.');
cuAssert(str_contains($css,'@media(max-width:600px)'),'Mobile layout required.');
cuAssert(str_contains($css,'min-height:44px'),'Mobile action targets must be at least 44px.');
cuAssert(!str_contains($css,'overflow-x:auto'),'Cockpit must not rely on page-level horizontal scrolling.');

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
