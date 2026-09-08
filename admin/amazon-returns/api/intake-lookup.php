<?php
declare(strict_types=1);

require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/Csrf.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/SpApi.php';
require_once __DIR__.'/../../../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__.'/../../../includes/amazon-returns/Projector.php';

SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function sv_amz_intake_lookup_reply(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if(($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='POST'){
    sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Método não permitido.'],405);
}

$input=json_decode((string)file_get_contents('php://input'),true);
if(!is_array($input))$input=$_POST;
$csrf=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
if(!SvAmazonReturnsCsrf::valid('amazon_returns_intake',$csrf)){
    sv_amz_intake_lookup_reply(['success'=>false,'error'=>'CSRF inválido.'],403);
}

$orderId=trim((string)($input['order_id'] ?? ''));
if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$orderId)!==1){
    sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Informe um número de pedido Amazon válido.'],422);
}

try{
    $db=amazon_returns_pdo();
    if(!$db instanceof PDO){
        sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
    }
    SvAmazonReturnsSchema::ensure($db);
    $config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $p=SvAmazonTenantPersistence::create($db,$context);

    $cases=$p->cases->forOrder($orderId);
    if($cases!==[]){
        sv_amz_intake_lookup_reply([
            'success'=>true,
            'cases'=>$cases,
            'source'=>'local',
            'synced'=>false,
        ]);
    }

    $spApi=new SvAmazonReturnsSpApi();
    $order=$spApi->syncOrder($orderId);
    $transactions=[];
    $financialRefreshed=true;
    try{
        $financial=$spApi->listTransactions($orderId);
        $transactions=is_array($financial['transactions'] ?? null)
            ? array_values(array_filter($financial['transactions'],'is_array')) : [];
    }catch(Throwable $e){
        $financialRefreshed=false;
        error_log('[amazon-returns-intake-lookup-financial] '.get_class($e));
    }

    $db->beginTransaction();
    try{
        SvAmazonSpApiEventSink::persist($p,$order,$transactions);
        $cases=$p->cases->forOrder($orderId);
        $projected=[];
        foreach($cases as $case){
            $caseId=(int)($case['id'] ?? 0);
            $projected[]=$caseId>0
                ? SvAmazonReturnProjector::project($p->cases,$p->events,$caseId)
                : $case;
        }
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }

    sv_amz_intake_lookup_reply([
        'success'=>true,
        'cases'=>$projected,
        'source'=>'amazon',
        'synced'=>true,
        'financial_refreshed'=>$financialRefreshed,
    ]);
}catch(InvalidArgumentException $e){
    error_log('[amazon-returns-intake-lookup-invalid] '.get_class($e));
    sv_amz_intake_lookup_reply([
        'success'=>false,
        'error'=>'Não foi possível consultar este pedido na Amazon.',
    ],422);
}catch(Throwable $e){
    error_log('[amazon-returns-intake-lookup] '.get_class($e));
    sv_amz_intake_lookup_reply([
        'success'=>false,
        'error'=>'Não foi possível localizar este pedido na Amazon agora. Tente novamente.',
    ],502);
}
