<?php
declare(strict_types=1);

require_once __DIR__ . '/../scripts/amazon-returns/d90-warehouse-closure-preflight.php';

function d90PreflightSame(mixed $expected,mixed $actual,string $name): void
{
    if($expected!==$actual){
        throw new RuntimeException($name.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
    }
}

$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE amazon_return_cases (
 id INTEGER PRIMARY KEY,tenant_id INTEGER,amazon_connection_id INTEGER,
 state TEXT,terminal_reason TEXT,physical_status TEXT,quantity_received INTEGER,
 refund_at TEXT,closed_at TEXT,updated_at TEXT,reconciled_credit_amount TEXT
)');
$db->exec('CREATE TABLE amazon_return_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER,amazon_connection_id INTEGER,
 case_id INTEGER,event_type TEXT,source TEXT,source_event_id TEXT,occurred_at TEXT,
 payload_json TEXT
)');
$db->exec('CREATE TABLE amazon_return_outbox (
 id INTEGER PRIMARY KEY,tenant_id INTEGER,amazon_connection_id INTEGER,case_id INTEGER,
 status TEXT
)');
$case=$db->prepare('INSERT INTO amazon_return_cases
 (id,tenant_id,amazon_connection_id,state,terminal_reason,physical_status,quantity_received,
 refund_at,closed_at,updated_at,reconciled_credit_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
foreach([101,102,103,104,105,106,107,108,109,110] as $id){
    $tenant=$id===107?2:1;
    $state=$id===110?'RECEIVED_OK':'CLOSED_LOSS';
    $reason=$id===110?'WAREHOUSE_RECEIVED_ADMIN_FINAL_D90':($id===106?'OWNER_CLOSED':'RECOVERY_WINDOW_EXPIRED_D90');
    $date=$id===103?'2026-11-01 00:00:00':'2026-07-01 00:00:00';
    $closed=$id===109?null:'2026-11-09 04:00:00';
    $case->execute([$id,$tenant,1,$state,$reason,'RECEIVED_OK',1,
        $date,$closed,'2026-11-10 00:00:00',$id===101?'7.25':'0.00']);
}
$event=$db->prepare('INSERT INTO amazon_return_events
 (tenant_id,amazon_connection_id,case_id,event_type,source,source_event_id,occurred_at,payload_json)
 VALUES (?,?,?,?,?,?,?,?)');
$eventId108=null;
foreach([101,103,104,105,107,108,109] as $id){
    $event->execute([$id===107?2:1,1,$id,'PHYSICAL_RECEIVED','WAREHOUSE',
        'receipt-'.$id,'2026-07-02 00:00:00','{}']);
    if($id===108)$eventId108=(int)$db->lastInsertId();
}
$event->execute([1,1,108,'PHYSICAL_RECEIPT_VOIDED','WAREHOUSE','void-108',
    '2026-08-02 00:00:00',json_encode(['operator_id'=>2,'receipt_event_id'=>$eventId108])]);
$event->execute([1,1,105,'CASE_CLOSED_ADMIN_90D','INTERNAL','close-105',
    '2026-11-09 00:00:00','{}']);
$event->execute([1,1,110,'CASE_D90_WAREHOUSE_CLOSURE_CORRECTED','INTERNAL','corrected-110',
    '2026-11-10 00:00:00','{}']);
$db->exec("INSERT INTO amazon_return_outbox VALUES (1,1,1,104,'PENDING')");
$priorRows=$db->query('SELECT * FROM amazon_return_cases ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$priorEvents=(int)$db->query('SELECT COUNT(*) FROM amazon_return_events')->fetchColumn();
$now=new DateTimeImmutable('2026-11-13 06:20:00',new DateTimeZone('UTC'));
$first=sv_amazon_d90_warehouse_preview($db,new SvAmazonTenantContext(1,1),$now);
$second=sv_amazon_d90_warehouse_preview($db,new SvAmazonTenantContext(1,1),$now);
d90PreflightSame($first,$second,'Read-only repeated scan must be deterministic.');
d90PreflightSame('READ_ONLY_PREVIEW',$first['verdict'],'The scan cannot claim recovery.');
d90PreflightSame(false,$first['database_mutated'],'No write executed.');
d90PreflightSame(false,$first['external_requests_sent'],'No external action.');
d90PreflightSame(false,$first['operator_grant_backup_lease_verified'],'Data proof never implies permission.');
d90PreflightSame(false,$first['repair_performed'],'No repair attempt.');
d90PreflightSame('tenant:1|connection:1',$first['scope'],'Tenant scope.');
$expected=[
 'candidate_rows'=>7,
 'warehouse_proof_valid'=>5,
 'warehouse_proof_missing_or_voided'=>2,
 'original_admin_closure_present'=>1,
 'active_outbox'=>1,
 'not_yet_d90'=>1,
 'closed_timestamp_missing'=>1,
 'eligible_by_data_only'=>1,
 'financial_credit_positive'=>1,
 'compensating_events'=>1,
];
d90PreflightSame($expected,$first['counts'],'Exact audited classifications.');
d90PreflightSame(64,strlen($first['candidate_version_set_sha256']),'Opaque manifest hash.');
d90PreflightSame($priorRows,$db->query('SELECT * FROM amazon_return_cases ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
    'No clinical or financial case mutation.');
d90PreflightSame($priorEvents,(int)$db->query('SELECT COUNT(*) FROM amazon_return_events')->fetchColumn(),
    'No event appended.');
d90PreflightSame(false,$db->inTransaction(),'Read-only transaction always ends.');
$other=sv_amazon_d90_warehouse_preview($db,new SvAmazonTenantContext(2,1),$now);
d90PreflightSame(1,$other['counts']['candidate_rows'],'Another tenant remains isolated.');
d90PreflightSame(1,$other['counts']['eligible_by_data_only'],'Second-tenant counting scoped.');
try {
    $db->beginTransaction();
    sv_amazon_d90_warehouse_preview($db,new SvAmazonTenantContext(1,1),$now);
    throw new RuntimeException('Nested transaction was not rejected.');
} catch (LogicException) {
    d90PreflightSame(true,$db->inTransaction(),'Caller transaction must not be modified.');
    $db->rollBack();
}
echo "D90_WAREHOUSE_CLOSURE_PREFLIGHT=PASS\n";
