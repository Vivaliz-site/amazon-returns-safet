<?php
declare(strict_types=1);

require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/CockpitTimeline.php';
require_once __DIR__.'/../../../includes/amazon-returns/Projector.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_case_reply(array $payload,int $status=200):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_case_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
    SvAmazonReturnsSchema::ensure($db);$config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
    $caseId=filter_input(INPUT_GET,'case_id',FILTER_VALIDATE_INT) ?: 0;$orderId=trim((string)($_GET['order_id']??''));
    if($caseId>0){
        $case=$p->cases->find($caseId);if(!is_array($case))sv_amz_case_reply(['success'=>false,'error'=>'Caso não encontrado.'],404);
        $case=SvAmazonReturnProjector::project($p->cases,$p->events,$caseId);
        $events=$p->events->eventsForCase($caseId);$reviews=$p->reviews->forCase($caseId);$apps=$p->ruleApplications->forCase($caseId);
        $timeline=SvAmazonCockpitTimeline::project($case,$events,$p->evidence->projectionForCase($caseId),$p->outbox->historyForCase($caseId),$reviews,$apps);
        $currentReview=null;for($i=count($reviews)-1;$i>=0;$i--){if(($reviews[$i]['status']??'')==='OPEN'){$currentReview=$reviews[$i];break;}}
        sv_amz_case_reply(['success'=>true,'case'=>$case,'timeline'=>$timeline,'current_review'=>$currentReview,'rule_applications'=>$apps]);
    }
    if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$orderId)!==1)sv_amz_case_reply(['success'=>false,'error'=>'Informe um pedido Amazon válido.'],422);
    sv_amz_case_reply(['success'=>true,'cases'=>$p->cases->forOrder($orderId)]);
}catch(Throwable $e){error_log('[amazon-returns-case] '.get_class($e));sv_amz_case_reply(['success'=>false,'error'=>'Não foi possível consultar o caso.'],500);}
