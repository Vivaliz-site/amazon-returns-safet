<?php
declare(strict_types=1);
function ruAssert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
$root=dirname(__DIR__);$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');$js=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit.js');$api=(string)file_get_contents($root.'/admin/amazon-returns/api/review.php');
foreach(['review-impact','review-preview','review-confirm'] as $needle)ruAssert(str_contains($page,$needle),'Review UI missing '.$needle);
foreach(['Aprovar sugestão','Alterar e aprovar','Escolher outra ação','Aguardar','Somente este caso'] as $label)ruAssert(str_contains($page,$label),'Review action missing '.$label);
foreach(['openReview','suggestReview','previewReview','confirmReview','expected_version'] as $needle)ruAssert(str_contains($js,$needle),'Review JS missing '.$needle);
ruAssert(str_contains($js,'review-decision.php'),'Review submit endpoint missing in JS.');
ruAssert(str_contains($js,'status===409'),'Stale review version must refresh rather than retry blindly.');
ruAssert(str_contains($js,'disabled=true'),'Double-click submit protection required.');
ruAssert(!str_contains($js,'innerHTML'),'Review UI cannot inject API HTML.');
foreach(['Qual decisão precisa ser tomada?','O que aconteceu','O que já foi verificado','Mensagens com a Amazon','Impacto financeiro e prazo','Recomendação','O que o sistema aprenderá','Casos semelhantes afetados'] as $heading){
    ruAssert(str_contains($js.$page,$heading),'Review business layout missing '.$heading);
}
foreach(['humanReviewQuestion','renderReviewFinancialImpact','renderReviewLearningImpact'] as $helper)ruAssert(str_contains($js,$helper),'Review presentation helper missing '.$helper);
ruAssert(!str_contains($js,"text('div',r.reason)"),'Raw review reason cannot be rendered.');
ruAssert(!str_contains($js,'JSON.stringify(ctx.facts)'),'Raw review facts cannot be rendered.');
ruAssert(str_contains($js,"item.category==='EXTERNAL_WRITE'"),'Review message direction must come from the audited timeline category.');
ruAssert(str_contains($js,'O conteúdo histórico dessa mensagem não foi armazenado.'),'Review must label unavailable historical outbound text honestly.');
foreach(['canonical_signature','signature_hash','effect_json'] as $token)ruAssert(!str_contains($js,$token),'Internal learning signature cannot reach normal review UI.');
ruAssert(str_contains($api,'SvAmazonReturnProjector::project'),'Review detail must use the same projected case facts as cockpit detail.');
ruAssert(str_contains($api,"'outstanding_amount'"),'Review detail must expose current financial exposure.');
ruAssert(str_contains($api,"'REVIEW_NOT_OPEN'"),'Resolved reviews must remain guarded and never be reopened by the UI.');
echo "review-ui-contract-test: OK\n";
