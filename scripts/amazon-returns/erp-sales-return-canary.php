#!/usr/bin/env php
<?php
declare(strict_types=1);

// Usage: --mode=discover | --mode=execute --case-id=123

require_once dirname(__DIR__,2).'/includes/Database.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Config.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantRegistry.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantPersistence.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/ErpInvoiceLookup.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/ErpReturnInvoiceLookup.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/ErpSalesReturnGateway.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/ErpSalesReturnService.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/ErpSalesReturnTask.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/ErpSalesReturnCanary.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/ErpApiRateLimiter.php';

function erp_canary_reply(array $payload,int $exit=0): never
{
    echo json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($exit);
}

$options=getopt('',['mode:','case-id:']);
$mode=strtolower(trim((string)($options['mode']??'')));
if(!in_array($mode,['discover','execute'],true)){
    erp_canary_reply(['status'=>'ERROR','reason'=>'MODE_REQUIRED','allowed_modes'=>['discover','execute']],2);
}
$requestedCaseId=null;
if(array_key_exists('case-id',$options)){
    $parsed=filter_var($options['case-id'],FILTER_VALIDATE_INT);
    if($parsed===false || $parsed<1)erp_canary_reply(['status'=>'ERROR','reason'=>'CASE_ID_INVALID'],2);
    $requestedCaseId=(int)$parsed;
}
try{
    $config=new SvAmazonReturnsConfig();
    $db=amazon_returns_require_pdo();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $p=SvAmazonTenantPersistence::create($db,$context);
    $stmt=$db->prepare(
        "SELECT * FROM amazon_return_erp_sales_returns WHERE tenant_id=:tenant_id "
        ."AND amazon_connection_id=:amazon_connection_id AND status='READY_TO_CREATE' "
        ."ORDER BY updated_at,amazon_order_id LIMIT 250"
    );
    if(!$stmt)throw new RuntimeException('Could not prepare ERP canary workflow query.');
    $stmt->execute([':tenant_id'=>$context->tenantId(),':amazon_connection_id'=>$context->amazonConnectionId()]);
    $workflows=array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    $saleLookup=new SvAmazonErpInvoiceLookup(null,null,$config);
    $returnLookup=new SvAmazonErpReturnInvoiceLookup(null,null,$config);
    $limiter=SvAmazonErpApiRateLimiter::fromConfig($config);
    $browserGateway=new SvAmazonOlistBrowserErpSalesReturnGateway(null,$config->get('OLIST_ERP_CDP_URL','http://127.0.0.1:9226'));
    $candidate=null;$candidateCases=[];$rejected=[];

    foreach($workflows as $workflow){
        $orderId=trim((string)($workflow['amazon_order_id']??''));
        if($orderId==='')continue;
        $cases=$p->cases->forOrder($orderId);
        $syntheticSale=[
            'order_id'=>$orderId,
            'invoice_id'=>trim((string)($workflow['original_invoice_id']??'')),
            'invoice_number'=>trim((string)($workflow['original_invoice_number']??'')),
        ];
        $preflight=SvAmazonErpSalesReturnCanary::evaluate($workflow,$cases,$syntheticSale,null);
        if(($preflight['eligible']??false)!==true){
            $rejected[(string)($preflight['reason']??'UNKNOWN')]=($rejected[(string)($preflight['reason']??'UNKNOWN')]??0)+1;
            continue;
        }
        if($requestedCaseId!==null && (int)($preflight['case_id']??0)!==$requestedCaseId)continue;
        $limiter->beforeRequest();
        $existingReturn=$returnLookup->findForOrder($orderId);
        if($existingReturn!==null){$rejected['RETURN_INVOICE_ALREADY_EXISTS']=($rejected['RETURN_INVOICE_ALREADY_EXISTS']??0)+1;continue;}
        $limiter->beforeRequest();
        $sale=$saleLookup->findSaleForOrder($orderId);
        $checked=SvAmazonErpSalesReturnCanary::evaluate($workflow,$cases,$sale,$existingReturn);
        if(($checked['eligible']??false)===true){
            $existingSalesReturn=$browserGateway->probeExisting(
                (string)$checked['original_invoice_id'],(string)$checked['original_invoice_number']
            );
            if($existingSalesReturn!==null){
                $rejected['ERP_SALES_RETURN_ALREADY_EXISTS']=($rejected['ERP_SALES_RETURN_ALREADY_EXISTS']??0)+1;
                continue;
            }
        }
        if(($checked['eligible']??false)!==true){
            $rejected[(string)($checked['reason']??'UNKNOWN')]=($rejected[(string)($checked['reason']??'UNKNOWN')]??0)+1;
            continue;
        }
        $candidate=$checked;$candidateCases=$cases;break;
    }

    if(!is_array($candidate)){
        erp_canary_reply([
            'status'=>'NO_SAFE_CANDIDATE','mode'=>$mode,'ready_workflows'=>count($workflows),
            'requested_case_id'=>$requestedCaseId,'rejected'=>$rejected,
        ],3);
    }

    if($mode==='discover'){
        erp_canary_reply([
            'status'=>'READY','mode'=>'discover','candidate'=>$candidate,
            'write_enabled'=>$config->erpSalesReturnCreateEnabled(),
            'write_case_allowed'=>$config->writeCaseAllowed((int)$candidate['case_id']),
        ]);
    }

    $caseId=(int)$candidate['case_id'];$orderId=(string)$candidate['order_id'];
    $writeConfig=$requestedCaseId===null
        ? new SvAmazonReturnsConfig(['AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'=>(string)$caseId])
        : $config;
    if(!$writeConfig->erpSalesReturnCreateEnabled())erp_canary_reply(['status'=>'BLOCKED','reason'=>'ERP_WRITE_GATE_DISABLED','case_id'=>$caseId,'order_id'=>$orderId],4);
    if(!SvAmazonErpSalesReturnCanary::exactWriteScope($writeConfig->get('AMAZON_RETURNS_WRITE_CANARY_CASE_IDS'),$caseId)){
        erp_canary_reply(['status'=>'BLOCKED','reason'=>'EXACT_CANARY_SCOPE_REQUIRED','case_id'=>$caseId,'order_id'=>$orderId],4);
    }
    if(!$writeConfig->writeCaseAllowed($caseId))erp_canary_reply(['status'=>'BLOCKED','reason'=>'CANARY_CASE_NOT_ALLOWLISTED','case_id'=>$caseId,'order_id'=>$orderId],4);
    if(!SvAmazonErpSalesReturnTask::writeAllowedForOrderCases($writeConfig,$candidateCases)){
        erp_canary_reply(['status'=>'BLOCKED','reason'=>'ORDER_CASE_SCOPE_NOT_ALLOWLISTED','case_id'=>$caseId,'order_id'=>$orderId],4);
    }

    $gateway=$browserGateway;
    $service=new SvAmazonErpSalesReturnService(
        $p->erpSalesReturns,$gateway,
        static fn(string $id):array=>$p->cases->forOrder($id),
        static fn(string $id):?array=>$saleLookup->findSaleForOrder($id),
        static fn(string $id):?array=>$returnLookup->findForOrder($id),
        true
    );
    $first=$service->reconcileOrder($orderId);
    if(strtoupper(trim((string)($first['status']??'')))!=='RETURN_CREATED_WAITING_INVOICE'){
        erp_canary_reply(['status'=>'FAILED','reason'=>'WRITE_NOT_PROVEN','case_id'=>$caseId,'order_id'=>$orderId,'workflow_status'=>$first['status']??null],5);
    }
    $externalId=trim((string)($first['erp_sales_return_id']??''));
    if(preg_match('/^[0-9]+$/D',$externalId)!==1)erp_canary_reply(['status'=>'FAILED','reason'=>'EXTERNAL_ID_MISSING','case_id'=>$caseId,'order_id'=>$orderId],5);
    $candidate['erp_sales_return_id']=$externalId;
    $readBack=$gateway->readBack($externalId,$orderId);
    if(!is_array($readBack) || !SvAmazonErpSalesReturnCanary::verifyExternalReadBack($readBack,$candidate)){
        erp_canary_reply(['status'=>'FAILED','reason'=>'EXTERNAL_READBACK_MISMATCH','case_id'=>$caseId,'order_id'=>$orderId,'erp_sales_return_id'=>$externalId],6);
    }
    $second=$service->reconcileOrder($orderId);
    $secondId=trim((string)($second['erp_sales_return_id']??''));
    if(strtoupper(trim((string)($second['status']??'')))!=='RETURN_CREATED_WAITING_INVOICE' || $secondId!==$externalId){
        erp_canary_reply(['status'=>'FAILED','reason'=>'IDEMPOTENCY_RECHECK_FAILED','case_id'=>$caseId,'order_id'=>$orderId,'erp_sales_return_id'=>$externalId],7);
    }
    erp_canary_reply([
        'status'=>'PROVEN','mode'=>'execute','case_id'=>$caseId,'order_id'=>$orderId,
        'erp_sales_return_id'=>$externalId,'external_readback'=>'MATCHED','idempotency_recheck'=>'MATCHED',
        'items'=>$candidate['items'],'refund_at'=>$candidate['refund_at'],
    ]);
}catch(Throwable $e){
    erp_canary_reply(['status'=>'ERROR','reason'=>'CANARY_RUNTIME_ERROR','error_class'=>$e::class],10);
}
