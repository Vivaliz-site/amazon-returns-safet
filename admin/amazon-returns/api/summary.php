<?php
declare(strict_types=1);

require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/CockpitHealth.php';
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
    $db->beginTransaction();
    $row=$p->cases->summary();
    $breakdown=[
        'at_risk'=>number_format((float)($row['at_risk']??0),2,'.',''),
        'eligible_now'=>number_format((float)($row['eligible_now']??0),2,'.',''),
        'safe_t_submitted'=>number_format((float)($row['safe_t_submitted']??0),2,'.',''),
        'denied'=>number_format((float)($row['denied']??0),2,'.',''),
        'appeal'=>number_format((float)($row['appeal']??0),2,'.',''),
        'support'=>number_format((float)($row['support']??0),2,'.',''),
        'approved_awaiting_credit'=>number_format((float)($row['approved_awaiting_credit']??0),2,'.',''),
        'recovered'=>number_format((float)($row['recovered']??0),2,'.',''),
        'loss'=>number_format((float)($row['loss']??0),2,'.',''),
    ];
    $money=$breakdown;
    $money['awaiting_credit']=$breakdown['approved_awaiting_credit'];
    $money['in_dispute']=number_format(
        (float)$breakdown['safe_t_submitted']+(float)$breakdown['denied']+
        (float)$breakdown['appeal']+(float)$breakdown['support'],2,'.',''
    );
    $money['breakdown']=$breakdown;
    $gates=[];
    foreach(['unclassified','eligible_without_action','expired_without_treatment','credit_without_reconciliation'] as $key){
        $gates[$key]=(int)($row[$key]??0);
    }
    $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $health=SvAmazonCockpitHealth::build($p,$config,$row,$now);
    $automationPreview=$p->cases->automationPreview(6);
    $deadlines=$p->cases->upcomingDeadlines(8);
    $recentCases=$p->cases->recent(50);
    $db->commit();
    $asOf=$now->format(DATE_ATOM);
    sv_amz_summary_reply([
        'success'=>true,
        'tenant_id'=>$context->tenantId(),
        'amazon_connection_id'=>$context->amazonConnectionId(),
        'as_of'=>$asOf,
        'checked_at'=>$asOf,
        'operator_status'=>$health['operator_status'],
        'human_action_count'=>$health['human_action_count'],
        'automatic_work_count'=>$health['automatic_work_count'],
        'concluded_count'=>$health['concluded_count'],
        'operational_problem_count'=>$health['operational_problem_count'],
        'last_successful_cycle_at'=>$health['last_successful_cycle_at'],
        'connectors'=>$health['connectors'],
        'operational_problems'=>$health['operational_problems'],
        'money'=>$money,
        'health_gates'=>$gates,
        'pending_reviews'=>$health['human_action_count'],
        'total_cases'=>(int)($row['total_cases']??0),
        'recent_cases'=>$recentCases,
        'automation_preview'=>$automationPreview,
        'deadlines'=>$deadlines,
    ]);
}catch(Throwable $e){
    if(isset($db) && $db instanceof PDO && $db->inTransaction())$db->rollBack();
    error_log('[amazon-returns-summary] '.get_class($e));
    sv_amz_summary_reply([
        'success'=>false,'error'=>'Não foi possível carregar o resumo.',
    ],500);
}
