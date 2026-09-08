<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/config/bootstrap-env.php';
require_once dirname(__DIR__,2).'/includes/Database.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Config.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Schema.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantRegistry.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantPersistence.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/RemoteBridge.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/BridgeService.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/HttpJsonRequest.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sv_amz_bridge_reply(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function sv_amz_bridge_auth_header(): string
{
    $apache=function_exists('apache_request_headers')?apache_request_headers():[];
    return SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader(
        $_SERVER,is_array($apache)?$apache:[]
    );
}

function sv_amz_bridge_service_reply(array $result): never
{
    $status=(int)($result['http_status'] ?? 200);
    unset($result['http_status']);
    sv_amz_bridge_reply($result,$status);
}

$expected=getenv('SELLER_CENTRAL_BRIDGE_TOKEN');
$expected=is_string($expected)?trim($expected):'';
if(!SvAmazonReturnsRemoteBridge::authorized($expected,sv_amz_bridge_auth_header())){
    header('WWW-Authenticate: Bearer realm="Amazon Returns SAFE-T Bridge"');
    sv_amz_bridge_reply(['status'=>'UNAUTHORIZED'],401);
}
if(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''))!=='POST'){
    header('Allow: POST');
    sv_amz_bridge_reply(['status'=>'METHOD_NOT_ALLOWED'],405);
}
if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>131072){
    sv_amz_bridge_reply(['status'=>'PAYLOAD_TOO_LARGE'],413);
}
try{
    $input=SvAmazonReturnsHttpJsonRequest::decodeObject((string)file_get_contents('php://input'),131072);
}catch(LengthException){
    sv_amz_bridge_reply(['status'=>'PAYLOAD_TOO_LARGE'],413);
}catch(UnexpectedValueException){
    sv_amz_bridge_reply(['status'=>'INVALID_JSON'],400);
}
$operation=strtolower(trim((string)($input['operation'] ?? '')));
if(!in_array($operation,['heartbeat','pull','result'],true)){
    sv_amz_bridge_reply(['status'=>'INVALID_OPERATION'],400);
}

$config=new SvAmazonReturnsConfig();
$db=amazon_returns_pdo();
if(!$db instanceof PDO)sv_amz_bridge_reply(['status'=>'DB_UNAVAILABLE'],503);
try{
    SvAmazonReturnsSchema::ensure($db);
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $p=SvAmazonTenantPersistence::create($db,$context);
    $service=new SvAmazonReturnsBridgeService($p,$config);
    if($operation==='heartbeat')sv_amz_bridge_reply($service->heartbeat((string)($input['worker_id'] ?? '')));
    if($operation==='pull')sv_amz_bridge_service_reply($service->pull());

    $jobId=filter_var($input['job_id'] ?? null,FILTER_VALIDATE_INT,[
        'options'=>['min_range'=>1],
    ]);
    $key=strtolower(trim((string)($input['idempotency_key'] ?? '')));
    $result=is_array($input['result'] ?? null)?$input['result']:[];
    if($jobId===false || $result===[]){
        sv_amz_bridge_reply(['status'=>'INVALID_RESULT'],400);
    }
    sv_amz_bridge_service_reply($service->acceptResult((int)$jobId,$key,$result));
}catch(Throwable $e){
    error_log('[amazon-returns-bridge] '.get_class($e));
    sv_amz_bridge_reply(['status'=>'SERVER_ERROR'],500);
}
