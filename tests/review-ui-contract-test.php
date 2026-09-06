<?php
declare(strict_types=1);
function ruAssert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$root=dirname(__DIR__);$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');$js=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit.js');
foreach(['review-impact','review-preview','review-confirm'] as $needle)ruAssert(str_contains($page,$needle),'Review UI missing '.$needle);
foreach(['Aprovar sugestão','Alterar e aprovar','Escolher outra ação','Aguardar','Somente este caso'] as $label)ruAssert(str_contains($page,$label),'Review action missing '.$label);
foreach(['openReview','suggestReview','previewReview','confirmReview','expected_version'] as $needle)ruAssert(str_contains($js,$needle),'Review JS missing '.$needle);
ruAssert(str_contains($js,'review-decision.php'),'Review submit endpoint missing in JS.');
ruAssert(str_contains($js,'status===409'),'Stale review version must refresh rather than retry blindly.');
ruAssert(str_contains($js,'disabled=true'),'Double-click submit protection required.');
ruAssert(!str_contains($js,'innerHTML'),'Review UI cannot inject API HTML.');
echo "review-ui-contract-test: OK\n";
