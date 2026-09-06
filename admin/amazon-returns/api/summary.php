<?php
declare(strict_types=1);

require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
SvAmazonReturnsAdminAuth::requireLogin(true);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function sv_amz_summary_reply(array $payload,int $status=200): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    $db=amazon_returns_pdo();
    if(!$db instanceof PDO){
        sv_amz_summary_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
    }
    SvAmazonReturnsSchema::ensure($db);
    $config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $p=SvAmazonTenantPersistence::create($db,$context);
    $row=$p->cases->summary();
    $money=[];
    foreach([
        'at_risk','eligible_now','safe_t_submitted','denied','appeal','support',
        'approved_awaiting_credit','recovered','loss',
    ] as $key){
        $money[$key]=number_format((float)($row[$key] ?? 0),2,'.','');
    }
    $gates=[];
    foreach([
        'unclassified','eligible_without_action','expired_without_treatment',
        'credit_without_reconciliation',
    ] as $key){
        $gates[$key]=(int)($row[$key] ?? 0);
    }
    sv_amz_summary_reply([
        'success'=>true,
        'tenant_id'=>$context->tenantId(),
        'amazon_connection_id'=>$context->amazonConnectionId(),
        'money'=>$money,
        'health_gates'=>$gates,
        'pending_reviews'=>$p->reviews->countOpen(),
        'total_cases'=>(int)($row['total_cases'] ?? 0),
        'recent_cases'=>$p->cases->recent(50),
        'checked_at'=>gmdate(DATE_ATOM),
    ]);
}catch(Throwable $e){
    error_log('[amazon-returns-summary] '.get_class($e));
    sv_amz_summary_reply([
        'success'=>false,'error'=>'Não foi possível carregar o resumo.',
    ],500);
}
