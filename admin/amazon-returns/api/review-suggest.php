<?php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Csrf.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/LearnedRuleEngine.php';
require_once __DIR__.'/../../../includes/amazon-returns/OpenAiReviewAdvisor.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function sv_amz_suggest_reply(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))sv_amz_suggest_reply(['success'=>false,'error'=>'JSON inválido.'],400);
if(!SvAmazonReturnsCsrf::valid('review-ai',$input['csrf_token']??null))sv_amz_suggest_reply(['success'=>false,'error'=>'CSRF'],403);
$reviewId=filter_var($input['review_id']??null,FILTER_VALIDATE_INT);
$expectedVersion=filter_var($input['expected_version']??null,FILTER_VALIDATE_INT);
if($reviewId===false||$reviewId<1||$expectedVersion===false||$expectedVersion<1)sv_amz_suggest_reply(['success'=>false,'error'=>'Entrada inválida.'],422);
try{
 $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_suggest_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
 SvAmazonReturnsSchema::ensure($db);$config=new SvAmazonReturnsConfig();
 $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
 $review=$p->reviews->find((int)$reviewId);if(!is_array($review))sv_amz_suggest_reply(['success'=>false,'error'=>'Revisão não encontrada.'],404);
 if(($review['status']??'')!=='OPEN'||(int)($review['version']??0)!==(int)$expectedVersion)sv_amz_suggest_reply(['success'=>false,'error'=>'STALE_REVIEW_VERSION'],409);
 $match=(new SvAmazonLearnedRuleEngine())->match(is_array($review['context']??null)?$review['context']:[],$p->learnedRules->active());
 if(($match['status']??'')==='MATCH')sv_amz_suggest_reply(['success'=>false,'error'=>'RULE_ALREADY_RESOLVED','rule_id'=>$match['rule']['id']??null],409);
 $advisor=new SvAmazonOpenAiReviewAdvisor($config);
 try{
  $suggestion=$advisor->suggest(is_array($review['context']??null)?$review['context']:[]);
  $saved=$p->reviews->saveSuggestion((int)$reviewId,(int)$expectedVersion,$suggestion,$advisor->model());
  sv_amz_suggest_reply(['success'=>true,'suggestion_available'=>true,'suggestion'=>$suggestion,'model'=>$advisor->model(),'version'=>(int)$saved['version']]);
 }catch(Throwable $e){
  if((int)$e->getCode()===409)sv_amz_suggest_reply(['success'=>false,'error'=>'STALE_REVIEW_VERSION'],409);
  try{$saved=$p->reviews->recordAiFailure((int)$reviewId,(int)$expectedVersion,$e::class);$version=(int)$saved['version'];}
  catch(Throwable $stale){if((int)$stale->getCode()===409)sv_amz_suggest_reply(['success'=>false,'error'=>'STALE_REVIEW_VERSION'],409);throw $stale;}
  sv_amz_suggest_reply(['success'=>true,'suggestion_available'=>false,'version'=>$version]);
 }
}catch(Throwable $e){error_log('[amazon-returns-review-suggest] '.$e::class);sv_amz_suggest_reply(['success'=>false,'error'=>'Não foi possível gerar sugestão.'],500);}
