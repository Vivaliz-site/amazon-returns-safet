<?php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/CockpitTimeline.php';
SvAmazonReturnsAdminAuth::requireLogin(true);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_review_reply(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_review_reply(['success'=>false,'error'=>'Banco indisponível.'],503);SvAmazonReturnsSchema::ensure($db);
 $config=new SvAmazonReturnsConfig();$context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
 $reviewId=filter_var($_GET['review_id']??null,FILTER_VALIDATE_INT);if($reviewId===false||$reviewId<1)sv_amz_review_reply(['success'=>false,'error'=>'review_id inválido.'],422);
 $review=$p->reviews->find((int)$reviewId);if(!is_array($review))sv_amz_review_reply(['success'=>false,'error'=>'Revisão não encontrada.'],404);
 $caseId=(int)($review['case_id']??0);$case=$p->cases->find($caseId);if(!is_array($case))sv_amz_review_reply(['success'=>false,'error'=>'Caso não encontrado.'],404);
 $events=$p->events->eventsForCase($caseId);$timeline=SvAmazonCockpitTimeline::project($case,$events,$p->evidence->projectionForCase($caseId),$p->outbox->historyForCase($caseId),$p->reviews->forCase($caseId),$p->ruleApplications->forCase($caseId));
 sv_amz_review_reply(['success'=>true,'review'=>$review,'case'=>$case,'timeline'=>$timeline]);
}catch(Throwable $e){error_log('[amazon-returns-review] '.get_class($e));sv_amz_review_reply(['success'=>false,'error'=>'Não foi possível consultar a revisão.'],500);}
