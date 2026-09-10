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
        $invoiceNumber=null;$returnReason=null;$lastRead=null;
        for($i=count($events)-1;$i>=0;$i--){
            $event=$events[$i];$type=strtoupper(trim((string)($event['event_type']??'')));$payload=is_array($event['payload']??null)?$event['payload']:[];
            if($invoiceNumber===null && $type==='SALES_INVOICE_LINKED'){
                $candidate=trim((string)($payload['invoice_number']??$payload['sales_invoice_number']??''));
                if($candidate!=='')$invoiceNumber=$candidate;
            }
            if($returnReason===null && $type==='RETURNS_REPORT_MATCHED'){
                $candidate=trim((string)($payload['return_reason']??''));if($candidate!=='')$returnReason=$candidate;
            }
            if($lastRead===null && in_array($type,['SAFE_T_STATUS_OBSERVED','SELLER_CENTRAL_ACTION_RESULT','SAFE_T_EMAIL_REVIEW_RESPONSE','FINANCIAL_TRANSACTION_OBSERVED','SAFE_T_REIMBURSEMENT_OBSERVED'],true)){
                $lastRead=['id'=>(int)($event['id']??0),'event_type'=>$type,'occurred_at'=>$event['occurred_at']??null,'source'=>$event['source']??null];
            }
        }
        $lastWrite=null;for($i=count($outbox)-1;$i>=0;$i--){
            $kind=strtoupper(trim((string)($outbox[$i]['kind']??'')));
            if(in_array($kind,['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)){
                $lastWrite=['id'=>(int)($outbox[$i]['id']??0),'kind'=>$kind,'status'=>$outbox[$i]['status']??null,'created_at'=>$outbox[$i]['created_at']??null,'updated_at'=>$outbox[$i]['updated_at']??null];break;
            }
        }
        $case['sales_invoice_number']=$invoiceNumber;$case['return_reason']=$returnReason;
        $case['last_external_write']=$lastWrite;$case['last_read_back']=$lastRead;
        sv_amz_case_reply(['success'=>true,'case'=>$case,'timeline'=>$timeline,'current_review'=>$currentReview,'rule_applications'=>$apps,'last_external_write'=>$lastWrite,'last_read_back'=>$lastRead]);
    }
    if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$orderId)!==1)sv_amz_case_reply(['success'=>false,'error'=>'Informe um pedido Amazon válido.'],422);
    sv_amz_case_reply(['success'=>true,'cases'=>$p->cases->forOrder($orderId)]);
}catch(Throwable $e){error_log('[amazon-returns-case] '.get_class($e));sv_amz_case_reply(['success'=>false,'error'=>'Não foi possível consultar o caso.'],500);}