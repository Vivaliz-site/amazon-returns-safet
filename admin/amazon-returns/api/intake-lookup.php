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
require_once __DIR__.'/../../../includes/amazon-returns/InvoiceSearch.php';
require_once __DIR__.'/../../../includes/amazon-returns/InvoiceRemoteLookup.php';
require_once __DIR__.'/../../../includes/amazon-returns/CaseReferenceSearch.php';
require_once __DIR__.'/../../../includes/amazon-returns/GmailApi.php';
require_once __DIR__.'/../../../includes/amazon-returns/GmailReturnReferenceLookup.php';
require_once __DIR__.'/../../../includes/amazon-returns/GmailEventSink.php';
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

/** @param list<int> $caseIds @return list<array<string,mixed>> */
function sv_amz_intake_lookup_project(SvAmazonTenantPersistence $p,array $caseIds): array
{
    $projected=[];
    foreach(array_values(array_unique($caseIds)) as $caseId){
        $caseId=(int)$caseId;
        if($caseId<1)continue;
        $case=$p->cases->find($caseId);
        if(!is_array($case))continue;
        $row=SvAmazonReturnProjector::project($p->cases,$p->events,$caseId);
        $tracks=is_array($row['return_tracking_ids']??null)?array_values($row['return_tracking_ids']):[];
        $row['return_tracking_ids']=$tracks;
        $row['return_tracking_id']=$tracks[0]??null;
        $projected[]=$row;
    }
    return $projected;
}

/** @param list<array<string,mixed>> $cases */
function sv_amz_intake_lookup_missing_product_title(array $cases): bool
{
    foreach($cases as $case){
        if(trim((string)($case['product_title']??''))==='')return true;
    }
    return false;
}

/** Persist normalized Amazon item titles as immutable event evidence. */
function sv_amz_intake_lookup_persist_product_titles(SvAmazonTenantPersistence $p,array $order): void
{
    $orderId=trim((string)($order['order_id']??''));
    if($orderId==='')return;
    $titles=[];
    $items=is_array($order['order_items']??null)?array_values(array_filter($order['order_items'],'is_array')):[];
    foreach($items as $item){
        $itemId=trim((string)($item['orderItemId']??$item['order_item_id']??''));
        $title=trim((string)($item['title']??''));
        if($itemId!=='' && $title!=='')$titles[$itemId]=$title;
    }
    if($titles===[])return;
    $requestId=trim((string)($order['request_id']??''));
    foreach($p->cases->forOrder($orderId) as $case){
        $caseId=(int)($case['id']??0);
        $itemId=trim((string)($case['amazon_order_item_id']??''));
        $title=$titles[$itemId]??'';
        if($caseId<1 || $title==='')continue;
        $p->events->append([
            'case_id'=>$caseId,
            'event_type'=>'PRODUCT_DETAILS_SYNCED',
            'source'=>'SP_API_ORDERS',
            'source_event_id'=>$requestId!==''?$requestId:null,
            'idempotency_key'=>hash('sha256','spapi-product-title|'.$p->context()->scopeKey().'|'.$orderId.'|'.$itemId.'|'.$title),
            'occurred_at'=>gmdate('Y-m-d H:i:s'),
            'payload'=>['product_title'=>$title,'financial_truth'=>false],
            'evidence_sha256'=>null,
        ]);
    }
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

$query=trim((string)($input['query'] ?? ''));
$legacyOrderId=trim((string)($input['order_id'] ?? ''));
$legacyInvoiceNumber=trim((string)($input['sales_invoice_number'] ?? ''));
if($query===''){
    if($legacyOrderId!=='' && $legacyInvoiceNumber!==''){
        sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Informe apenas um identificador por vez.'],422);
    }
    if($legacyOrderId!=='' && preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$legacyOrderId)!==1){
        sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Informe um número de pedido Amazon válido.'],422);
    }
    if($legacyInvoiceNumber!=='' && preg_match('/^[0-9]{1,20}$/',$legacyInvoiceNumber)!==1){
        sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Informe somente os números da NF de venda.'],422);
    }
    $query=$legacyOrderId!==''?$legacyOrderId:$legacyInvoiceNumber;
}
if($query==='' || strlen($query)>96 || str_contains($query,"\0")){
    sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Informe pedido Amazon, NF de venda ou TBR / rastreio da devolução.'],422);
}
$queryKind=SvAmazonCaseReferenceSearch::kind($query);

try{
    $db=amazon_returns_pdo();
    if(!$db instanceof PDO){
        sv_amz_intake_lookup_reply(['success'=>false,'error'=>'Banco indisponível.'],503);
    }
    SvAmazonReturnsSchema::ensure($db);
    $config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $p=SvAmazonTenantPersistence::create($db,$context);
    $localCaseIds=SvAmazonCaseReferenceSearch::caseIds($db,$context,$query);
    if($localCaseIds!==[]){
        $localProjected=sv_amz_intake_lookup_project($p,$localCaseIds);
        if(sv_amz_intake_lookup_missing_product_title($localProjected)){
            $orderIds=array_values(array_unique(array_filter(array_map(
                static fn(array $row):string=>trim((string)($row['amazon_order_id']??'')),$localProjected
            ))));
            if(count($orderIds)===1){
                try{
                    $titleApi=new SvAmazonReturnsSpApi();
                    $titleOrder=$titleApi->syncOrder($orderIds[0]);
                    $db->beginTransaction();
                    sv_amz_intake_lookup_persist_product_titles($p,$titleOrder);
                    $localProjected=sv_amz_intake_lookup_project($p,$localCaseIds);
                    $db->commit();
                }catch(Throwable $titleError){
                    if($db->inTransaction())$db->rollBack();
                    error_log('[amazon-returns-intake-product-title] '.get_class($titleError).': '.$titleError->getMessage());
                }
            }
        }
        sv_amz_intake_lookup_reply([
            'success'=>true,
            'cases'=>$localProjected,
            'source'=>'local',
            'synced'=>false,
        ]);
    }

    $invoiceLookup=null;
    $returnLookup=null;
    $spApi=null;
    $orderId='';

    if($queryKind===SvAmazonCaseReferenceSearch::INVOICE){
        $invoiceNumber=$query;
        $invoiceLookups=(new SvAmazonInvoiceRemoteLookup())
            ->findOrdersByInvoiceNumber($invoiceNumber);
        if($invoiceLookups===[]){
            sv_amz_intake_lookup_reply([
                'success'=>true,'cases'=>[],'source'=>'invoice_remote','synced'=>false,
            ]);
        }

        $projected=[];
        $synced=false;
        $financialRefreshed=true;
        $syncFailures=[];
        $sourceKinds=[];
        foreach($invoiceLookups as $candidateLookup){
            if(!is_array($candidateLookup))continue;
            $candidateOrderId=trim((string)($candidateLookup['order_id']??''));
            if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$candidateOrderId)!==1)continue;
            $sourceKinds[(string)($candidateLookup['source']??'')]=true;
            $candidateCases=$p->cases->forOrder($candidateOrderId);
            $order=null;
            $transactions=[];
            if($candidateCases===[]){
                try{
                    if(!$spApi instanceof SvAmazonReturnsSpApi)$spApi=new SvAmazonReturnsSpApi();
                    $order=$spApi->syncOrder($candidateOrderId);
                    try{
                        $financial=$spApi->listTransactions($candidateOrderId);
                        $transactions=is_array($financial['transactions']??null)
                            ? array_values(array_filter($financial['transactions'],'is_array')) : [];
                    }catch(Throwable $financialError){
                        $financialRefreshed=false;
                        error_log('[amazon-returns-intake-lookup-financial] '.get_class($financialError).': '.$financialError->getMessage());
                    }
                    $synced=true;
                }catch(Throwable $syncError){
                    $syncFailures[]=$syncError;
                    error_log('[amazon-returns-intake-invoice-candidate] order='.$candidateOrderId.' class='.get_class($syncError));
                    continue;
                }
            }

            $db->beginTransaction();
            try{
                if(is_array($order)){
                    SvAmazonSpApiEventSink::persist($p,$order,$transactions);
                    sv_amz_intake_lookup_persist_product_titles($p,$order);
                }
                $candidateCases=$p->cases->forOrder($candidateOrderId);
                foreach($candidateCases as $case){
                    $caseId=(int)($case['id']??0);
                    if($caseId>0)$p->events->append(
                        SvAmazonInvoiceSearch::evidenceEvent($caseId,$candidateLookup)
                    );
                }
                $caseIds=array_values(array_filter(array_map(
                    static fn(array $row):int=>(int)($row['id']??0),$candidateCases
                ),static fn(int $id):bool=>$id>0));
                foreach(sv_amz_intake_lookup_project($p,$caseIds) as $row){
                    $projected[(int)($row['id']??0)]=$row;
                }
                $db->commit();
            }catch(Throwable $candidateError){
                if($db->inTransaction())$db->rollBack();
                throw $candidateError;
            }
        }

        if($projected===[] && $syncFailures!==[])throw $syncFailures[0];
        $sourceKeys=array_keys($sourceKinds);
        $remoteSource=$sourceKeys!==[] && count($sourceKeys)===1
            ? ($sourceKeys[0]==='ERP_OLIST_INVOICE'?'erp_invoice':($sourceKeys[0]==='SP_API_INVOICES'?'amazon_invoice':'invoice_remote'))
            : 'invoice_remote';
        sv_amz_intake_lookup_reply([
            'success'=>true,
            'cases'=>array_values($projected),
            'source'=>$remoteSource,
            'synced'=>$synced,
            'partial'=>$syncFailures!==[],
            'financial_refreshed'=>$financialRefreshed,
        ]);
    }elseif($queryKind===SvAmazonCaseReferenceSearch::RETURN_TRACKING){
        if((($config->readiness()['gmail']['ready']??false)!==true)){
            sv_amz_intake_lookup_reply([
                'success'=>true,'cases'=>[],'source'=>'return_tracking','synced'=>false,
            ]);
        }
        $returnLookup=(new SvAmazonGmailReturnReferenceLookup(new SvAmazonGmailApiClient($config)))->find($query);
        if(!is_array($returnLookup)){
            sv_amz_intake_lookup_reply([
                'success'=>true,'cases'=>[],'source'=>'return_tracking','synced'=>false,
            ]);
        }
        $orderId=trim((string)($returnLookup['order_id']??''));
        if($orderId==='')throw new RuntimeException('Resolved return reference has no order.');
        $existing=$p->cases->forOrder($orderId);
        if($existing!==[]){
            $ids=array_values(array_filter(array_map(
                static fn(array $row):int=>(int)($row['id']??0),$existing
            ),static fn(int $id):bool=>$id>0));
            $projected=sv_amz_intake_lookup_project($p,$ids);
            $titleOrder=null;
            if(sv_amz_intake_lookup_missing_product_title($projected)){
                try{
                    $spApi=new SvAmazonReturnsSpApi();
                    $titleOrder=$spApi->syncOrder($orderId);
                }catch(Throwable $titleError){
                    error_log('[amazon-returns-intake-product-title] '.get_class($titleError).': '.$titleError->getMessage());
                }
            }
            $db->beginTransaction();
            try{
                SvAmazonGmailEventSink::persist($p,$returnLookup['event']);
                if(is_array($titleOrder))sv_amz_intake_lookup_persist_product_titles($p,$titleOrder);
                $owned=$p->cases->forOrder($orderId);
                $ids=array_values(array_filter(array_map(
                    static fn(array $row):int=>(int)($row['id']??0),$owned
                ),static fn(int $id):bool=>$id>0));
                $projected=sv_amz_intake_lookup_project($p,$ids);
                $db->commit();
            }catch(Throwable $e){
                if($db->inTransaction())$db->rollBack();
                throw $e;
            }
            sv_amz_intake_lookup_reply([
                'success'=>true,'cases'=>$projected,'source'=>'gmail_return_tracking','synced'=>false,
            ]);
        }
    }elseif($queryKind===SvAmazonCaseReferenceSearch::ORDER){
        $orderId=$query;
    }else{
        sv_amz_intake_lookup_reply([
            'success'=>true,'cases'=>[],'source'=>'local','synced'=>false,
        ]);
    }

    if($orderId==='')throw new InvalidArgumentException('Lookup could not resolve an Amazon order.');
    if(!$spApi instanceof SvAmazonReturnsSpApi)$spApi=new SvAmazonReturnsSpApi();
    $order=$spApi->syncOrder($orderId);
    $transactions=[];
    $financialRefreshed=true;
    try{
        $financial=$spApi->listTransactions($orderId);
        $transactions=is_array($financial['transactions'] ?? null)
            ? array_values(array_filter($financial['transactions'],'is_array')) : [];
    }catch(Throwable $e){
        $financialRefreshed=false;
        error_log('[amazon-returns-intake-lookup-financial] '.get_class($e).': '.$e->getMessage());
    }

    $db->beginTransaction();
    try{
        SvAmazonSpApiEventSink::persist($p,$order,$transactions);
        sv_amz_intake_lookup_persist_product_titles($p,$order);
        if(is_array($returnLookup)){
            SvAmazonGmailEventSink::persist($p,$returnLookup['event']);
        }
        $cases=$p->cases->forOrder($orderId);
        if(is_array($invoiceLookup)){
            foreach($cases as $case){
                $caseId=(int)($case['id'] ?? 0);
                if($caseId>0)$p->events->append(
                    SvAmazonInvoiceSearch::evidenceEvent($caseId,$invoiceLookup)
                );
            }
        }
        $caseIds=array_values(array_filter(array_map(
            static fn(array $row):int=>(int)($row['id']??0),$p->cases->forOrder($orderId)
        ),static fn(int $id):bool=>$id>0));
        $projected=sv_amz_intake_lookup_project($p,$caseIds);
        $db->commit();
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        throw $e;
    }

    $remoteSource=is_array($invoiceLookup)
        ? ((string)($invoiceLookup['source'] ?? '')==='ERP_OLIST_INVOICE' ? 'erp_invoice' : 'amazon_invoice')
        : (is_array($returnLookup)?'gmail_return_tracking':'amazon');
    sv_amz_intake_lookup_reply([
        'success'=>true,
        'cases'=>$projected,
        'source'=>$remoteSource,
        'synced'=>true,
        'financial_refreshed'=>$financialRefreshed,
    ]);
}catch(SvAmazonInvoiceAccessException $e){
    error_log('[amazon-returns-intake-lookup-invoice-access] '.get_class($e).': '.$e->getMessage());
    sv_amz_intake_lookup_reply([
        'success'=>false,
        'error'=>'A Amazon ainda não autorizou a consulta por NF nesta conta.',
    ],403);
}catch(InvalidArgumentException $e){
    error_log('[amazon-returns-intake-lookup-invalid] '.get_class($e).': '.$e->getMessage());
    sv_amz_intake_lookup_reply([
        'success'=>false,
        'error'=>'Não foi possível consultar os dados informados.',
    ],422);
}catch(Throwable $e){
    error_log('[amazon-returns-intake-lookup] '.get_class($e).': '.$e->getMessage());
    sv_amz_intake_lookup_reply([
        'success'=>false,
        'error'=>'Não foi possível localizar a devolução agora. Tente novamente.',
    ],502);
}
