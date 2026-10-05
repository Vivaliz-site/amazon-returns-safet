<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/config/bootstrap-env.php';
require_once dirname(__DIR__,2).'/includes/Database.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Config.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Schema.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantRegistry.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantPersistence.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/HttpJsonRequest.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/CloudflareEmailIngress.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/GmailEventSink.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function sv_amz_email_ingress_reply(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$accessJwt=trim((string)($_SERVER["HTTP_CF_ACCESS_JWT_ASSERTION"] ?? ""));
$cfRay=trim((string)($_SERVER["HTTP_CF_RAY"] ?? ""));
if($accessJwt==="" || substr_count($accessJwt,".")!==2 || $cfRay===""){
    sv_amz_email_ingress_reply(["status"=>"UNAUTHORIZED"],401);
}
if(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''))!=='POST'){
    header('Allow: POST');
    sv_amz_email_ingress_reply(['status'=>'METHOD_NOT_ALLOWED'],405);
}

if((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>393216){
    sv_amz_email_ingress_reply(['status'=>'PAYLOAD_TOO_LARGE'],413);
}

try{
    $input=SvAmazonReturnsHttpJsonRequest::decodeObject(
        (string)file_get_contents('php://input'),
        393216
    );
    $events=SvAmazonCloudflareEmailIngress::parse($input);
}catch(LengthException){
    sv_amz_email_ingress_reply(['status'=>'PAYLOAD_TOO_LARGE'],413);
}catch(InvalidArgumentException|UnexpectedValueException){
    sv_amz_email_ingress_reply(['status'=>'INVALID_EMAIL_PAYLOAD'],400);
}

$db=amazon_returns_pdo();
if(!$db instanceof PDO){
    sv_amz_email_ingress_reply(['status'=>'DB_UNAVAILABLE'],503);
}

try{
    SvAmazonReturnsSchema::ensure($db);
    $config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $p=SvAmazonTenantPersistence::create($db,$context);
    $persisted=0;
    foreach($events as $event){
        SvAmazonGmailEventSink::persist($p,$event);
        $persisted++;
    }
    sv_amz_email_ingress_reply([
        'status'=>'OK',
        'parsed'=>count($events),
        'persisted'=>$persisted,
        'ingress'=>'CLOUDFLARE_EMAIL',
    ]);
}catch(Throwable $e){
    error_log('[amazon-returns-email-ingress] '.get_class($e));
    sv_amz_email_ingress_reply(['status'=>'SERVER_ERROR'],500);
}
