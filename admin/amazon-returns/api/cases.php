<?php
declare(strict_types=1);

require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/CockpitFilters.php';
require_once __DIR__.'/../../../includes/amazon-returns/InvoiceSearch.php';
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
    $sqlFilters=$filters->sqlFilters();$searchTerm=trim((string)($sqlFilters['q']??''));
    if($searchTerm!=='')unset($sqlFilters['q']);
    $requiresPostFilter=$filters->requiresDecisionFilter() || $searchTerm!=='';
    $queryPage=$requiresPostFilter?1:$filters->page();$queryPerPage=$requiresPostFilter?1000:$filters->perPage();
    $found=$p->cases->search($sqlFilters,$queryPage,$queryPerPage);
    $invoiceCaseIds=$searchTerm!==''?SvAmazonInvoiceSearch::caseIds($db,$context,$searchTerm):[];
    $needle=$searchTerm!==''?mb_strtolower($searchTerm,'UTF-8'):'';
    $items=[];$policies=$p->policies->allActive();
    foreach($found['items'] as $row){
        $caseId=(int)($row['id']??0);if($caseId<1)continue;
        $case=SvAmazonReturnProjector::project($p->cases,$p->events,$caseId);$case['policies']=$policies;
        if($needle!==''){
            $matches=in_array($caseId,$invoiceCaseIds,true);
            foreach(['amazon_order_id','safe_t_id','sku','asin'] as $field){
                $value=mb_strtolower(trim((string)($case[$field]??'')),'UTF-8');
                if($value!==''&&str_contains($value,$needle)){$matches=true;break;}
            }
            if(!$matches&&is_array($case['customer_tracking_ids']??null)){
                foreach($case['customer_tracking_ids'] as $trackingId){
                    $value=mb_strtolower(trim((string)$trackingId),'UTF-8');
                    if($value!==''&&str_contains($value,$needle)){$matches=true;break;}
                }
            }
            if(!$matches)continue;
        }
        $timeline=$p->events->eventsForCase($caseId);$policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
        $decision=$coordinator->previewAction($case,$timeline,$policy,$now);
        if($filters->action()!==null && strtoupper((string)($decision['action']??''))!==$filters->action())continue;
        $reviews=$p->reviews->forCase($caseId);$currentReview=null;
        for($i=count($reviews)-1;$i>=0;$i--){if(($reviews[$i]['status']??'')==='OPEN'){$currentReview=$reviews[$i];break;}}
        $apps=$p->ruleApplications->forCase($caseId);$app=$apps!==[]?$apps[array_key_last($apps)]:null;
        $history=$p->outbox->historyForCase($caseId);$lastWrite=null;
        for($i=count($history)-1;$i>=0;$i--){if(in_array($history[$i]['kind']??'',['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)){$lastWrite=$history[$i];break;}}
        $lastRead=null;for($i=count($timeline)-1;$i>=0;$i--){if(in_array($timeline[$i]['event_type']??'',['SAFE_T_STATUS_OBSERVED','SELLER_CENTRAL_ACTION_RESULT','SAFE_T_EMAIL_REVIEW_RESPONSE','FINANCIAL_TRANSACTION_OBSERVED','SAFE_T_REIMBURSEMENT_OBSERVED'],true)){$lastRead=$timeline[$i];break;}}
        $expected=(float)($case['expected_reimbursement_amount']??0);$refund=(float)($case['refund_amount']??0);$credit=(float)($case['reconciled_credit_amount']??0);
        $outstanding=max(0,($expected>0?$expected:$refund)-$credit);
        $items[]=[
            'id'=>$caseId,'amazon_order_id'=>$case['amazon_order_id']??null,'amazon_order_item_id'=>$case['amazon_order_item_id']??null,
            'sku'=>$case['sku']??null,'asin'=>$case['asin']??null,'program'=>$case['program']??null,'safe_t_id'=>$case['safe_t_id']??null,'support_case_id'=>$case['support_case_id']??null,
            'state'=>$case['state']??null,'physical_status'=>$case['physical_status']??null,
            'order_at'=>$case['order_at']??null,'refund_at'=>$case['refund_at']??null,'seller_debit_at'=>$case['seller_debit_at']??null,
            'refund_amount'=>$refund,'expected_reimbursement_amount'=>$expected,'reconciled_credit_amount'=>$credit,'outstanding_amount'=>$outstanding,
            'quantity_ordered'=>(int)($case['quantity_ordered']??0),'quantity_refunded'=>(int)($case['quantity_refunded']??0),'quantity_received'=>(int)($case['quantity_received']??0),
            'customer_delivery_confirmed'=>(bool)($case['customer_delivery_confirmed']??false),
            'customer_tracking_ids'=>array_values(is_array($case['customer_tracking_ids']??null)?$case['customer_tracking_ids']:[]),
            'customer_delivery_carriers'=>array_values(is_array($case['customer_delivery_carriers']??null)?$case['customer_delivery_carriers']:[]),
            'eligibility_at'=>$policy['eligibility_at']??($case['eligibility_at']??null),'next_action_at'=>$case['next_action_at']??null,'appeal_deadline_at'=>$case['appeal_deadline_at']??null,
            'current_action'=>$decision['action']??'WAIT','current_reason'=>$decision['reason']??null,
            'review_status'=>$currentReview['status']??null,'review_id'=>$currentReview['id']??null,
            'applied_rule'=>$app?['rule_id'=>(int)($app['rule_id']??0),'version'=>(int)($app['rule_version']??0),'result'=>$app['result']??null,'outcome'=>$app['outcome']??null]:null,
            'last_external_write'=>$lastWrite?['id'=>(int)$lastWrite['id'],'kind'=>$lastWrite['kind'],'status'=>$lastWrite['status'],'updated_at'=>$lastWrite['updated_at']??null,'created_at'=>$lastWrite['created_at']??null]:null,
            'last_read_back'=>$lastRead?['id'=>(int)$lastRead['id'],'event_type'=>$lastRead['event_type'],'occurred_at'=>$lastRead['occurred_at']??null,'source'=>$lastRead['source']??null]:null,
            'closed_at'=>$case['closed_at']??null,'updated_at'=>$case['updated_at']??null,
        ];
    }
    if($requiresPostFilter){$total=count($items);$offset=($filters->page()-1)*$filters->perPage();$items=array_slice($items,$offset,$filters->perPage());}
    else{$total=(int)$found['total'];}
    sv_amz_cases_reply(['success'=>true,'items'=>$items,'page'=>$filters->page(),'per_page'=>$filters->perPage(),'total'=>$total,'filters'=>$filters->filters()]);
}catch(InvalidArgumentException $e){sv_amz_cases_reply(['success'=>false,'error'=>'Filtros inválidos.'],422);}
catch(Throwable $e){error_log('[amazon-returns-cases] '.get_class($e));sv_amz_cases_reply(['success'=>false,'error'=>'Não foi possível consultar os casos.'],500);}