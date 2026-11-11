#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Audited correction of an incorrectly registered warehouse receipt.
 *
 * Dry-run by default. Example:
 * php scripts/amazon-returns/correct-warehouse-receipt.php \
 *   --case-id=101669 --receipt-event-id=1267182 \
 *   --order-id=701-6883818-0162606 \
 *   --reason=OWNER_CORRECTION_NOT_RECEIVED \
 *   --evidence-ref=https://github.com/Vivaliz-site/amazon-returns-safet/issues/532
 *
 * Only append and project with --execute after independent source check,
 * existing admin authority, no competing operator and official deployment.
 */
require_once dirname(__DIR__,2).'/includes/Database.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Config.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantRegistry.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantPersistence.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Projector.php';

function intakeCorrectionReply(array $data,int $exit=0): never
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    exit($exit);
}
$args=getopt('',['case-id:','receipt-event-id:','order-id:','reason:','evidence-ref:','execute']);
$caseId=filter_var($args['case-id']??null,FILTER_VALIDATE_INT);
$receiptEventId=filter_var($args['receipt-event-id']??null,FILTER_VALIDATE_INT);
$orderId=trim((string)($args['order-id']??''));
$reason=trim((string)($args['reason']??''));
$reference=trim((string)($args['evidence-ref']??''));
$execute=array_key_exists('execute',$args);
if($caseId===false || $caseId<1 || $receiptEventId===false || $receiptEventId<1
    || preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/',$orderId)!==1
    || strlen($reason)<8 || strlen($reason)>128
    || strlen($reference)<12 || strlen($reference)>512){
    intakeCorrectionReply(['status'=>'INVALID_ARGUMENTS'],2);
}
try {
    $db=amazon_returns_require_pdo();
    $config=new SvAmazonReturnsConfig();
    $scope=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    if($scope->tenantId()!==1 || $scope->amazonConnectionId()!==1) {
        intakeCorrectionReply(['status'=>'SCOPE_UNEXPECTED'],3);
    }
    if(!$execute)$db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    $p=SvAmazonTenantPersistence::create($db,$scope);
    if($execute)$p->cases->assertOwned((int)$caseId);
    $case=$p->cases->find((int)$caseId);
    if(!is_array($case) || (string)$case['amazon_order_id']!==$orderId){
        $db->rollBack();intakeCorrectionReply(['status'=>'ORDER_OR_CASE_MISMATCH'],3);
    }
    $timeline=$p->events->eventsForCase((int)$caseId);
    $original=null;
    foreach($timeline as $event)if((int)($event['id']??0)===(int)$receiptEventId){
        $original=$event;break;
    }
    if(!is_array($original) || ($original['event_type']??'')!=='PHYSICAL_RECEIVED'
        || ($original['source']??'')!=='WAREHOUSE'
        || (int)($original['case_id']??0)!==(int)$caseId){
        $db->rollBack();intakeCorrectionReply(['status'=>'WAREHOUSE_RECEIPT_NOT_FOUND'],3);
    }
    $key=SvAmazonTenantReturnEventStore::deterministicKey(
        'warehouse-receipt-void-v1',$scope->scopeKey(),(string)$caseId,(string)$receiptEventId
    );
    $existing=$p->events->findIdByIdempotencyKey($key);
    if($existing!==null){
        $current=SvAmazonReturnProjector::projectFrom($case,$timeline);
        $db->rollBack();
        intakeCorrectionReply([
            'status'=>'ALREADY_CORRECTED','case_id'=>(int)$caseId,'receipt_event_id'=>(int)$receiptEventId,
            'correction_event_id'=>$existing,'quantity_received'=>$current['quantity_received'],
            'physical_status'=>$current['physical_status'],'safe_t_id'=>$case['safe_t_id'],
        ]);
    }
    $now=gmdate('Y-m-d H:i:s');
    $payload=[
        'receipt_event_id'=>(int)$receiptEventId,'reason'=>$reason,'evidence_ref'=>$reference,
        'operator_id'=>max(1,(int)(function_exists('posix_geteuid')?posix_geteuid():getmyuid())),
        'actor'=>'GPT_EXECUTOR_ON_OWNER_INSTRUCTION','external_claims_unchanged'=>true,
        'financial_amounts_unchanged'=>true,'erp_and_stock_unchanged'=>true,
    ];
    $correction=[
        'case_id'=>(int)$caseId,'event_type'=>'PHYSICAL_RECEIPT_VOIDED',
        'source'=>'WAREHOUSE',
        'source_event_id'=>'receipt-void-'.$caseId.'-'.$receiptEventId,
        'idempotency_key'=>$key,'occurred_at'=>$now,'payload'=>$payload,
        'evidence_sha256'=>hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)),
    ];
    $before=[
        'quantity_received'=>(int)$case['quantity_received'],'physical_status'=>$case['physical_status'],
        'state'=>$case['state'],'reconciled_credit_amount'=>$case['reconciled_credit_amount'],
        'safe_t_id'=>$case['safe_t_id'],
    ];
    $expected=SvAmazonReturnProjector::projectFrom(
        $case,[...$timeline, ['id'=>PHP_INT_MAX]+$correction]
    );
    $after=[
        'quantity_received'=>(int)$expected['quantity_received'],
        'physical_status'=>$expected['physical_status'],
        'state'=>$expected['state'],
        'reconciled_credit_amount'=>$expected['reconciled_credit_amount'],
        'safe_t_id'=>$expected['safe_t_id'],
    ];
    if($before['quantity_received']<=0 || $after['quantity_received'] >= $before['quantity_received']
        || $after['safe_t_id']!==$before['safe_t_id']
        || $after['reconciled_credit_amount']!==$before['reconciled_credit_amount']){
        $db->rollBack();
        intakeCorrectionReply(['status'=>'PRECONDITIONS_NOT_MET','case_id'=>$caseId,'before'=>$before,'simulated'=>$after],3);
    }
    if(!$execute){
        $db->rollBack();
        intakeCorrectionReply([
            'status'=>'DRY_RUN_READY','case_id'=>$caseId,'receipt_event_id'=>$receiptEventId,
            'before'=>$before,'simulated'=>$after,'execute'=>false,
        ]);
    }
    $eventId=$p->events->append($correction);
    $projected=SvAmazonReturnProjector::project($p->cases,$p->events,(int)$caseId);
    if((int)$projected['quantity_received']!==(int)$after['quantity_received']
        || (string)$projected['physical_status']!==(string)$after['physical_status']){
        throw new RuntimeException('Correction projection did not match the scoped preflight.');
    }
    $db->commit();
    intakeCorrectionReply([
        'status'=>'CORRECTED','case_id'=>$caseId,'receipt_event_id'=>$receiptEventId,
        'correction_event_id'=>$eventId,
        'before'=>$before,'after'=>$after,
    ]);
} catch (Throwable $e) {
    if(isset($db) && $db instanceof PDO && $db->inTransaction())$db->rollBack();
    error_log('[warehouse-receipt-correction] '.get_class($e).': '.$e->getMessage());
    intakeCorrectionReply(['status'=>'ERROR','reason'=>get_class($e)],4);
}
