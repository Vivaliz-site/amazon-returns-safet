<?php
declare(strict_types=1);

require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/CockpitFilters.php';
require_once __DIR__.'/../../../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../../../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__.'/../../../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../../../includes/amazon-returns/DecisionCoordinator.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_cases_reply(array $payload,int $status=200):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_cases_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
    SvAmazonReturnsSchema::ensure($db);$config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
    $filters=SvAmazonCockpitFilters::fromQuery($_GET);$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $coordinator=new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$p,$config);
    $queryPage=$filters->requiresDecisionFilter()?1:$filters->page();$queryPerPage=$filters->requiresDecisionFilter()?1000:$filters->perPage();
    $found=$p->cases->search($filters->sqlFilters(),$queryPage,$queryPerPage);$items=[];
    $policies=$p->policies->allActive();
    foreach($found['items'] as $row){
        $caseId=(int)($row['id']??0);if($caseId<1)continue;
        $case=SvAmazonReturnProjector::project($p->cases,$p->events,$caseId);$case['policies']=$policies;
        $timeline=$p->events->eventsForCase($caseId);$policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
        $decision=$coordinator->previewAction($case,$timeline,$policy,$now);
        if($filters->action()!==null && strtoupper((string)($decision['action']??''))!==$filters->action())continue;
        $reviews=$p->reviews->forCase($caseId);$currentReview=null;
        for($i=count($reviews)-1;$i>=0;$i--){if(($reviews[$i]['status']??'')==='OPEN'){$currentReview=$reviews[$i];break;}}
        $apps=$p->ruleApplications->forCase($caseId);$app=$apps!==[]?$apps[array_key_last($apps)]:null;
        $history=$p->outbox->historyForCase($caseId);$lastWrite=null;
        for($i=count($history)-1;$i>=0;$i--){if(in_array($history[$i]['kind']??'',['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)){$lastWrite=$history[$i];break;}}
        $lastRead=null;for($i=count($timeline)-1;$i>=0;$i--){if(in_array($timeline[$i]['event_type']??'',['SAFE_T_STATUS_OBSERVED','SELLER_CENTRAL_ACTION_RESULT','SAFE_T_EMAIL_REVIEW_RESPONSE'],true)){$lastRead=$timeline[$i];break;}}
        $expected=(float)($case['expected_reimbursement_amount']??0);$refund=(float)($case['refund_amount']??0);$credit=(float)($case['reconciled_credit_amount']??0);
        $outstanding=max(0,($expected>0?$expected:$refund)-$credit);
        $items[]=[
            'id'=>$caseId,'amazon_order_id'=>$case['amazon_order_id']??null,'amazon_order_item_id'=>$case['amazon_order_item_id']??null,
            'sku'=>$case['sku']??null,'asin'=>$case['asin']??null,'program'=>$case['program']??null,'safe_t_id'=>$case['safe_t_id']??null,'support_case_id'=>$case['support_case_id']??null,
            'state'=>$case['state']??null,'physical_status'=>$case['physical_status']??null,'refund_at'=>$case['refund_at']??null,'eligibility_at'=>$policy['eligibility_at']??($case['eligibility_at']??null),
            'next_action_at'=>$case['next_action_at']??null,'appeal_deadline_at'=>$case['appeal_deadline_at']??null,'expected_reimbursement_amount'=>$expected,'reconciled_credit_amount'=>$credit,'outstanding_amount'=>$outstanding,
            'current_action'=>$decision['action']??'WAIT','current_reason'=>$decision['reason']??null,
            'review_status'=>$currentReview['status']??null,'review_id'=>$currentReview['id']??null,
            'applied_rule'=>$app?['rule_id'=>(int)($app['rule_id']??0),'version'=>(int)($app['rule_version']??0),'result'=>$app['result']??null,'outcome'=>$app['outcome']??null]:null,
            'last_external_write'=>$lastWrite?['id'=>(int)$lastWrite['id'],'kind'=>$lastWrite['kind'],'status'=>$lastWrite['status'],'updated_at'=>$lastWrite['updated_at']??null]:null,
            'last_read_back'=>$lastRead?['id'=>(int)$lastRead['id'],'event_type'=>$lastRead['event_type'],'occurred_at'=>$lastRead['occurred_at']??null,'source'=>$lastRead['source']??null]:null,
            'updated_at'=>$case['updated_at']??null,
        ];
    }
    if($filters->requiresDecisionFilter()){
        $total=count($items);$offset=($filters->page()-1)*$filters->perPage();$items=array_slice($items,$offset,$filters->perPage());
    }else{$total=(int)$found['total'];}
    sv_amz_cases_reply(['success'=>true,'items'=>$items,'page'=>$filters->page(),'per_page'=>$filters->perPage(),'total'=>$total,'filters'=>$filters->filters()]);
}catch(InvalidArgumentException $e){sv_amz_cases_reply(['success'=>false,'error'=>'Filtros inválidos.'],422);}
catch(Throwable $e){error_log('[amazon-returns-cases] '.get_class($e));sv_amz_cases_reply(['success'=>false,'error'=>'Não foi possível consultar os casos.'],500);}
