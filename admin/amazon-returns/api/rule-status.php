<?php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Csrf.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_rule_status_reply(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
$input=json_decode((string)file_get_contents('php://input'),true);if(!is_array($input))sv_amz_rule_status_reply(['success'=>false,'error'=>'JSON inválido.'],400);
if(!SvAmazonReturnsCsrf::valid('rule-status',$input['csrf_token']??null))sv_amz_rule_status_reply(['success'=>false,'error'=>'CSRF'],403);
$ruleId=filter_var($input['rule_id']??null,FILTER_VALIDATE_INT);$expectedVersion=filter_var($input['expected_version']??null,FILTER_VALIDATE_INT);
if($ruleId===false||$ruleId<1||$expectedVersion===false||$expectedVersion<1)sv_amz_rule_status_reply(['success'=>false,'error'=>'Entrada inválida.'],422);
if(($input['status']??null)!=='DISABLED')sv_amz_rule_status_reply(['success'=>false,'error'=>'Unsupported rule transition'],422);
try{
 $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_rule_status_reply(['success'=>false,'error'=>'Banco indisponível.'],503);SvAmazonReturnsSchema::ensure($db);
 $config=new SvAmazonReturnsConfig();$context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
 $rule=$p->learnedRules->setStatus((int)$ruleId,(int)$expectedVersion,'DISABLED');sv_amz_rule_status_reply(['success'=>true,'rule'=>$rule]);
}catch(Throwable $e){if((int)$e->getCode()===409)sv_amz_rule_status_reply(['success'=>false,'error'=>'STALE_RULE_VERSION'],409);error_log('[amazon-returns-rule-status] '.$e::class);sv_amz_rule_status_reply(['success'=>false,'error'=>'Não foi possível desabilitar a regra.'],500);}
