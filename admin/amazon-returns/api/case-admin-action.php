<?php
declare(strict_types=1);
require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Csrf.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/AdminCaseOverride.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_admin_action_reply(array $body,int $status=200):never{http_response_code($status);echo json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')sv_amz_admin_action_reply(['success'=>false,'error'=>'Método não permitido.'],405);
$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))sv_amz_admin_action_reply(['success'=>false,'error'=>'JSON inválido.'],400);
if(!SvAmazonReturnsCsrf::valid('case-admin-action',$input['csrf_token']??null))sv_amz_admin_action_reply(['success'=>false,'error'=>'CSRF'],403);
$caseId=filter_var($input['case_id']??null,FILTER_VALIDATE_INT);
if($caseId===false||$caseId<1||!is_string($input['action']??null)||!is_array($input['fields']??[]))sv_amz_admin_action_reply(['success'=>false,'error'=>'Entrada inválida.'],422);
try{
 $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_admin_action_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
 SvAmazonReturnsSchema::ensure($db);$config=new SvAmazonReturnsConfig();$context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
 $result=SvAmazonAdminCaseOverride::apply($p,(int)$caseId,(string)$input['action'],is_array($input['fields']??null)?$input['fields']:[],(string)($input['reason']??''),SvAmazonReturnsAdminAuth::username(),(string)($input['request_id']??''),(string)($input['expected_updated_at']??''));
 sv_amz_admin_action_reply(['success'=>true,'result'=>$result,'notice'=>'Alteração administrativa interna registrada. Nenhuma operação externa foi enviada à Amazon.']);
}catch(InvalidArgumentException $e){sv_amz_admin_action_reply(['success'=>false,'error'=>$e->getMessage()],422);}
catch(Throwable $e){
 if(in_array($e->getMessage(),['STALE_CASE_VERSION'],true))sv_amz_admin_action_reply(['success'=>false,'error'=>'O caso foi atualizado por outro processo. Recarregue os dados e tente novamente.'],409);
 if(in_array($e->getMessage(),['CASE_NOT_FOUND'],true))sv_amz_admin_action_reply(['success'=>false,'error'=>'Caso não encontrado.'],404);
 error_log('[amazon-returns-admin-case-action] '.$e::class);sv_amz_admin_action_reply(['success'=>false,'error'=>'Não foi possível salvar a intervenção administrativa.'],500);
}
