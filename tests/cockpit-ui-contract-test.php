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
foreach(['Pesquisar','Estado','Ação','Programa','Físico','Prazo'] as $label)cuAssert(str_contains($page,$label),'Cockpit filter label missing '.$label);
cuAssert(str_contains($page,'data-view="cases"'),'Cases tab required.');
cuAssert(str_contains($page,'data-view="reviews"'),'Reviews tab required.');
cuAssert(!str_contains($js,'innerHTML'),'API text must not be injected as HTML.');
foreach(['textContent','createElement','loadCases','loadReviews','openCase','renderTimeline'] as $needle)cuAssert(str_contains($js,$needle),'Cockpit JS missing '.$needle);
cuAssert(str_contains($js,"Intl.NumberFormat('pt-BR'"),'BRL formatter required.');
cuAssert(str_contains($js,"toLocaleString('pt-BR'"),'pt-BR date formatter required.');
cuAssert(str_contains($css,'@media(max-width:600px)'),'Mobile layout required.');
cuAssert(str_contains($css,'min-height:44px'),'Mobile action targets must be at least 44px.');
cuAssert(!str_contains($css,'overflow-x:auto'),'Cockpit must not rely on page-level horizontal scrolling.');
echo "cockpit-ui-contract-test: OK\n";
