<?php
declare(strict_types=1);

require_once __DIR__.'/../../../includes/AdminAuth.php';
require_once __DIR__.'/../../../includes/Database.php';
require_once __DIR__.'/../../../includes/amazon-returns/Config.php';
require_once __DIR__.'/../../../includes/amazon-returns/Schema.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__.'/../../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../../includes/amazon-returns/CockpitTimeline.php';
require_once __DIR__.'/../../../includes/amazon-returns/Projector.php';
require_once __DIR__.'/../../../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__.'/../../../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../../../includes/amazon-returns/DecisionCoordinator.php';
require_once __DIR__.'/../../../includes/amazon-returns/CaseConsultation.php';
require_once __DIR__.'/../../../includes/amazon-returns/ErpSalesReturnPresentation.php';
SvAmazonReturnsAdminAuth::requireLogin(true);
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
function sv_amz_case_reply(array $payload,int $status=200):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}

try{
    $db=amazon_returns_pdo();if(!$db instanceof PDO)sv_amz_case_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
    SvAmazonReturnsSchema::ensure($db);$config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);$p=SvAmazonTenantPersistence::create($db,$context);
    $caseId=filter_input(INPUT_GET,'case_id',FILTER_VALIDATE_INT) ?: 0;$orderId=trim((string)($_GET['order_id']??''));
    if($caseId>0){
        $case=$p->cases->find($caseId);if(!is_array($case))sv_amz_case_reply(['success'=>false,'error'=>'Caso não encontrado.'],404);
        $case=SvAmazonReturnProjector::project($p->cases,$p->events,$caseId);
        $events=$p->events->eventsForCase($caseId);$reviews=$p->reviews->forCase($caseId);$apps=$p->ruleApplications->forCase($caseId);
        $evidence=$p->evidence->projectionForCase($caseId);$outbox=$p->outbox->historyForCase($caseId);
        $timeline=SvAmazonCockpitTimeline::project($case,$events,$evidence,$outbox,$reviews,$apps);
        $currentReview=null;for($i=count($reviews)-1;$i>=0;$i--){if(($reviews[$i]['status']??'')==='OPEN'){$currentReview=$reviews[$i];break;}}
        $case['policies']=$p->policies->allActive();$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
        $coordinator=new SvAmazonDecisionCoordinator(new SvAmazonSafeTDecisionEngine(),$p,$config);
        $decision=$coordinator->previewAction($case,$events,$policy,$now);
        $invoiceFacts=SvAmazonCaseConsultation::invoiceFacts($events);
        $financialFacts=SvAmazonCaseConsultation::financialFacts($case,$events);
        $invoiceNumber=null;$returnReason=null;$lastRead=null;$physicalReceivedAt=null;$amazonDecisionAt=null;
        for($i=count($events)-1;$i>=0;$i--){
            $event=$events[$i];$type=strtoupper(trim((string)($event['event_type']??'')));$payload=is_array($event['payload']??null)?$event['payload']:[];
            if($invoiceNumber===null && $type==='SALES_INVOICE_LINKED'){$candidate=trim((string)($payload['invoice_number']??$payload['sales_invoice_number']??''));if($candidate!=='')$invoiceNumber=$candidate;}
            if($returnReason===null && in_array($type,['RETURN_REPORT_OBSERVED','RETURNS_REPORT_MATCHED'],true)){$candidate=trim((string)($payload['return_reason']??''));if($candidate!=='')$returnReason=$candidate;}
            if($physicalReceivedAt===null && $type==='PHYSICAL_RECEIVED' && strtoupper(trim((string)($event['source']??'')))==='WAREHOUSE'){$candidate=trim((string)($event['occurred_at']??''));if($candidate!=='')$physicalReceivedAt=$candidate;}
            if($amazonDecisionAt===null && $type==='SAFE_T_STATUS_OBSERVED'){$status=strtoupper(trim((string)($payload['claim_status']??$payload['status']??'')));if($status!=='' && !in_array($status,['PENDING','SUBMITTED','IN_PROGRESS','UNKNOWN','AUTH_REQUIRED'],true)){$candidate=trim((string)($event['occurred_at']??''));if($candidate!=='')$amazonDecisionAt=$candidate;}}
            if($lastRead===null && in_array($type,['SAFE_T_STATUS_OBSERVED','SELLER_CENTRAL_ACTION_RESULT','SAFE_T_EMAIL_REVIEW_RESPONSE','FINANCIAL_TRANSACTION_OBSERVED','SAFE_T_REIMBURSEMENT_OBSERVED'],true)){$lastRead=['id'=>(int)($event['id']??0),'event_type'=>$type,'occurred_at'=>$event['occurred_at']??null,'source'=>$event['source']??null];}
        }
        $lastWrite=null;for($i=count($outbox)-1;$i>=0;$i--){$kind=strtoupper(trim((string)($outbox[$i]['kind']??'')));if(in_array($kind,['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)){$lastWrite=['id'=>(int)($outbox[$i]['id']??0),'kind'=>$kind,'status'=>$outbox[$i]['status']??null,'created_at'=>$outbox[$i]['created_at']??null,'updated_at'=>$outbox[$i]['updated_at']??null];break;}}
        $safeTSubmittedAt=null;foreach($outbox as $write){$kind=strtoupper(trim((string)($write['kind']??'')));$status=strtoupper(trim((string)($write['status']??'')));if($kind!=='SAFE_T_SUBMIT'||!in_array($status,['SUCCEEDED','SUCCESS'],true))continue;$candidate=trim((string)($write['updated_at']??$write['created_at']??''));if($candidate!=='' && ($safeTSubmittedAt===null || strcmp($candidate,$safeTSubmittedAt)<0))$safeTSubmittedAt=$candidate;}
        $expected=(float)($case['expected_reimbursement_amount']??0);$refund=(float)($case['refund_amount']??0);$credit=(float)($case['reconciled_credit_amount']??0);
        $returnTracks=array_values(is_array($case['return_tracking_ids']??null)?$case['return_tracking_ids']:[]);
        $latestApp=$apps!==[]?$apps[array_key_last($apps)]:null;
        $appliedRule=is_array($latestApp)?[
            'rule_id'=>(int)($latestApp['rule_id']??0),
            'version'=>(int)($latestApp['rule_version']??0),
            'result'=>$latestApp['result']??null,
            'outcome'=>$latestApp['outcome']??null,
        ]:null;
        $erpReturnRow=$p->erpSalesReturns->findByOrder((string)($case['amazon_order_id']??''));$erpReturn=SvAmazonErpSalesReturnPresentation::project($erpReturnRow);
        $salesInvoiceNumber=$invoiceFacts['sales_invoice_number']??$invoiceNumber;$salesInvoiceSource=$invoiceFacts['sales_invoice_source']??null;
        if(($salesInvoiceNumber===null||$salesInvoiceNumber==='') && is_array($erpReturnRow)){$candidate=trim((string)($erpReturnRow['original_invoice_number']??''));if($candidate!==''){$salesInvoiceNumber=$candidate;$salesInvoiceSource='ERP_OLIST_INVOICE';}}
        $returnInvoiceNumbers=array_values(is_array($invoiceFacts['return_invoice_numbers']??null)?$invoiceFacts['return_invoice_numbers']:[]);$erpReturnNumber=trim((string)($erpReturn['invoice_number']??''));if($erpReturnNumber!==''&&!in_array($erpReturnNumber,$returnInvoiceNumbers,true))$returnInvoiceNumbers[]=$erpReturnNumber;
        $case['sales_invoice_number']=$salesInvoiceNumber;$case['sales_invoice_source']=$salesInvoiceSource;$case['return_invoice_numbers']=$returnInvoiceNumbers;
        $case['amazon_reimbursement_at']=$financialFacts['amazon_reimbursement_at']??null;$case['amazon_reimbursement_amount']=$financialFacts['amazon_reimbursement_amount']??'0.00';
        $case['safe_t_submitted_at']=$safeTSubmittedAt;$case['amazon_decision_at']=$amazonDecisionAt;
        $case['return_reason']=$returnReason;$case['physical_received_at']=$physicalReceivedAt;$case['last_external_write']=$lastWrite;$case['last_read_back']=$lastRead;
        $case['return_tracking_ids']=$returnTracks;$case['return_tracking_id']=$returnTracks[0]??null;$case['applied_rule']=$appliedRule;
        $case['current_action']=$decision['action']??'WAIT';$case['current_reason']=$decision['reason']??null;$case['eligibility_at']=$policy['eligibility_at']??($case['eligibility_at']??null);
        $case['next_action_at']=array_key_exists('next_action_at',$decision)?$decision['next_action_at']:($case['next_action_at']??null);
        $case['outstanding_amount']=max(0,($expected>0?$expected:$refund)-$credit);
        $case['erp_return']=$erpReturn;
        sv_amz_case_reply(['success'=>true,'case'=>$case,'timeline'=>$timeline,'current_review'=>$currentReview,'rule_applications'=>$apps,'last_external_write'=>$lastWrite,'last_read_back'=>$lastRead]);
    }
    if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$orderId)!==1)sv_amz_case_reply(['success'=>false,'error'=>'Informe um pedido Amazon válido.'],422);
    $erpReturn=SvAmazonErpSalesReturnPresentation::project($p->erpSalesReturns->findByOrder($orderId));
    $cases=$p->cases->forOrder($orderId);foreach($cases as &$row)$row['erp_return']=$erpReturn;unset($row);
    sv_amz_case_reply(['success'=>true,'cases'=>$cases]);
}catch(Throwable $e){error_log('[amazon-returns-case] '.get_class($e));sv_amz_case_reply(['success'=>false,'error'=>'Não foi possível consultar o caso.'],500);}