<?php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_rules_reply(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
try{
 $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_rules_reply(['success'=>false,'error'=>'Banco indisponível.'],503);SvAmazonReturnsSchema::ensure($db);
 $config=new SvAmazonReturnsConfig();$context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
 $status=strtoupper(trim((string)($_GET['status']??'')));$filters=[];
 if($status!==''){if(!in_array($status,['ACTIVE','SUPERSEDED','DISABLED'],true))sv_amz_rules_reply(['success'=>false,'error'=>'status inválido.'],422);$filters['status']=$status;}
 $items=[];
 foreach($p->learnedRules->list($filters) as $rule){
  $apps=$p->ruleApplications->forRule((int)$rule['id'],200);$review=$p->reviews->find((int)$rule['source_review_id']);
  $outcomes=[];foreach($apps as $app)$outcomes[(string)($app['outcome']??'PENDING')]=($outcomes[(string)($app['outcome']??'PENDING')]??0)+1;
  $items[]=$rule+['application_count'=>count($apps),'latest_outcomes'=>$outcomes,'source_case_id'=>(int)($review['case_id']??0)];
 }
 sv_amz_rules_reply(['success'=>true,'rules'=>$items]);
}catch(Throwable $e){error_log('[amazon-returns-rules] '.$e::class);sv_amz_rules_reply(['success'=>false,'error'=>'Não foi possível consultar a memória.'],500);}
