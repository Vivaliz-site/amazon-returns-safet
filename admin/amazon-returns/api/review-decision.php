<?php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Csrf.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../../../includes/amazon-returns/DecisionCoordinator.php';
require_once __DIR__.'/../../../includes/amazon-returns/ReviewService.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
function sv_amz_decision_reply(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))sv_amz_decision_reply(['success'=>false,'error'=>'JSON inválido.'],400);
if(!SvAmazonReturnsCsrf::valid('review-decision',$input['csrf_token']??null))sv_amz_decision_reply(['success'=>false,'error'=>'CSRF'],403);
$reviewId=filter_var($input['review_id']??null,FILTER_VALIDATE_INT);$expectedVersion=filter_var($input['expected_version']??null,FILTER_VALIDATE_INT);
$decision=is_array($input['decision']??null)?$input['decision']:null;
if($reviewId===false||$reviewId<1||$expectedVersion===false||$expectedVersion<1||$decision===null)sv_amz_decision_reply(['success'=>false,'error'=>'Entrada inválida.'],422);
try{
 $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_decision_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
 SvAmazonReturnsSchema::ensure($db);$config=new SvAmazonReturnsConfig();
 $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
 $coordinator=new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$p,$config);
 $service=new SvAmazonReviewService($p,$coordinator,null,$config);
 $result=$service->submit((int)$reviewId,(int)$expectedVersion,$decision,SvAmazonReturnsAdminAuth::username());
 sv_amz_decision_reply(['success'=>true,'result'=>$result]);
}catch(InvalidArgumentException $e){sv_amz_decision_reply(['success'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){
 if((int)$e->getCode()===409||in_array($e->getMessage(),['STALE_REVIEW_VERSION','REVIEW_NOT_OPEN'],true))sv_amz_decision_reply(['success'=>false,'error'=>'STALE_REVIEW_VERSION'],409);
 error_log('[amazon-returns-review-decision] '.$e::class);sv_amz_decision_reply(['success'=>false,'error'=>'Não foi possível salvar a decisão.'],500);
}
