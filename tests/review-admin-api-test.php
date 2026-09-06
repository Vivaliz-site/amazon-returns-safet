<?php
declare(strict_types=1);
function raAssert(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);}
function raSource(string $f):string{$p=dirname(__DIR__).'/'.$f;if(!is_file($p))throw new RuntimeException('Missing '.$f);return(string)file_get_contents($p);}
foreach(['review-suggest.php','review-preview.php','review-decision.php'] as $name){
 $src=raSource('admin/amazon-returns/api/'.$name);
 raAssert(str_contains($src,'AdminAuth.php'),$name.' requires admin auth');
 raAssert(str_contains($src,'Csrf.php'),$name.' requires CSRF utility');
 raAssert(str_contains($src,'SvAmazonReturnsCsrf::valid'),$name.' validates CSRF');
 raAssert(str_contains($src,'expected_version'),$name.' requires optimistic version');
 raAssert(str_contains($src,'TenantRegistry'),$name.' resolves tenant server-side');
 raAssert(str_contains($src,'TenantPersistence'),$name.' uses scoped persistence');
 raAssert(!str_contains($src,'outbox->claimBatch'),$name.' cannot execute external worker directly');
 raAssert(!str_contains($src,'BridgeService'),$name.' cannot invoke bridge directly');
}
$suggest=raSource('admin/amazon-returns/api/review-suggest.php');
raAssert(str_contains($suggest,'SvAmazonOpenAiReviewAdvisor'),'suggest endpoint uses advisory AI');
raAssert(str_contains($suggest,'saveSuggestion'),'suggest endpoint persists structured suggestion');
raAssert(str_contains($suggest,'recordAiFailure'),'AI failures persist bounded telemetry');
$preview=raSource('admin/amazon-returns/api/review-preview.php');
raAssert(str_contains($preview,'->preview('),'impact preview delegates to ReviewService preview');
raAssert(!str_contains($preview,'->submit('),'preview must not mutate review/rule state');
$decision=raSource('admin/amazon-returns/api/review-decision.php');
raAssert(str_contains($decision,'->submit('),'decision endpoint delegates only to ReviewService submit');
raAssert(str_contains($decision,'STALE_REVIEW_VERSION'),'stale decision maps to HTTP 409');
echo "review-admin-api-test: OK\n";
