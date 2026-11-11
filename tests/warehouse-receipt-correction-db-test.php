<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../includes/amazon-returns/WarehouseReceiptCorrections.php';
function vSame(mixed $a,mixed $b,string $why):void {
    if($a!==$b)throw new RuntimeException($why.' expected='.json_encode($a).' actual='.json_encode($b));
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE amazon_return_cases (
 id INTEGER PRIMARY KEY,tenant_id INTEGER NOT NULL,amazon_connection_id INTEGER NOT NULL,
 state TEXT NOT NULL,physical_status TEXT NOT NULL,quantity_received INTEGER NOT NULL DEFAULT 0,
 refund_at TEXT,closed_at TEXT,amazon_order_id TEXT NOT NULL
)");
$db->exec("CREATE TABLE amazon_return_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,tenant_id INTEGER NOT NULL,amazon_connection_id INTEGER NOT NULL,
 case_id INTEGER NOT NULL,event_type TEXT NOT NULL,source TEXT NOT NULL,source_event_id TEXT,
 idempotency_key TEXT NOT NULL UNIQUE,occurred_at TEXT NOT NULL,payload_json TEXT NOT NULL,
 evidence_sha256 TEXT,created_at TEXT NOT NULL
)");
$db->exec("INSERT INTO amazon_return_cases VALUES
 (101669,1,1,'CREDIT_PENDING','NOT_RECEIVED',0,'2026-07-01 00:00:00',NULL,'701-6883818-0162606')");
$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,1));
$id=$p->events->append([
    'case_id'=>101669,'event_type'=>'PHYSICAL_RECEIVED','source'=>'WAREHOUSE',
    'source_event_id'=>'op-original','idempotency_key'=>hash('sha256','receipt'),
    'occurred_at'=>'2026-07-10 10:00:00',
    'payload'=>['quantity'=>1,'condition'=>'OK','operator_id'=>42],
]);
vSame($id,$p->cases->warehousePhysicalReceiptEventId(101669),'live intake exists');
vSame([], $p->cases->agedUnreceivedCaseIds('2026-09-30 00:00:00'), 'unretracted warehouse receipt excludes D90');
$voidId=$p->events->append([
    'case_id'=>101669,'event_type'=>'WAREHOUSE_RECEIPT_VOIDED','source'=>'ADMIN_AUDIT',
    'source_event_id'=>'op-retract','idempotency_key'=>hash('sha256','void'),
    'occurred_at'=>'2026-10-04 15:00:00',
    'payload'=>['receipt_event_id'=>$id,'reason'=>'OWNER_CORRECTION_NOT_RECEIVED',
        'evidence_ref'=>'https://github.com/Vivaliz-site/amazon-returns-safet/issues/532'],
]);
vSame(null,$p->cases->warehousePhysicalReceiptEventId(101669),'retracted receipt no longer warehouse proof');
vSame([101669],$p->cases->agedUnreceivedCaseIds('2026-09-30 00:00:00'),'old D90 candidates no longer masked by retracted receipt');
vSame($voidId,$p->events->findIdByIdempotencyKey(hash('sha256','void')),
    'replay lookup finds same compensating event ID without inserting');
vSame(2,(int)$db->query('SELECT COUNT(*) FROM amazon_return_events')->fetchColumn(),'ledger remains append-only with no duplicates');
echo "warehouse-receipt-correction-db-test: OK\n";
