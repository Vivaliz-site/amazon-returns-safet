<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/D90WarehouseClosureRepair.php';

function d90wrSame(mixed $expected, mixed $actual, string $why): void {
    if ($expected !== $actual) {
        throw new RuntimeException($why . ': expected=' . var_export($expected, true)
            . ' got=' . var_export($actual, true));
    }
}
final class D90WarehouseRepairSqlitePdo extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$db = new D90WarehouseRepairSqlitePdo('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE amazon_return_cases (
    id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, amazon_connection_id INTEGER NOT NULL,
    state TEXT NOT NULL, terminal_reason TEXT, physical_status TEXT NOT NULL,
    refund_at TEXT, closed_at TEXT, next_action_at TEXT, updated_at TEXT,
    expected_reimbursement_amount TEXT, reconciled_credit_amount TEXT,
    safe_t_id TEXT, support_case_id TEXT
)');
$db->exec('CREATE TABLE amazon_return_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    amazon_connection_id INTEGER NOT NULL, case_id INTEGER NOT NULL,
    event_type TEXT NOT NULL, source TEXT NOT NULL, source_event_id TEXT,
    idempotency_key TEXT NOT NULL UNIQUE, occurred_at TEXT NOT NULL,
    payload_json TEXT NOT NULL, evidence_sha256 TEXT, created_at TEXT NOT NULL
)');
$db->exec('CREATE TABLE amazon_return_outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    amazon_connection_id INTEGER NOT NULL, case_id INTEGER NOT NULL,
    status TEXT NOT NULL, kind TEXT NOT NULL
)');

$seed = $db->prepare('INSERT INTO amazon_return_cases
    (id,tenant_id,amazon_connection_id,state,terminal_reason,physical_status,
     refund_at,closed_at,next_action_at,updated_at,expected_reimbursement_amount,
     reconciled_credit_amount,safe_t_id,support_case_id)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([101,102,103,104,105,106,107,108] as $id) {
    $tenant=$id === 105 ? 2 : 1;
    $state=$id === 106 ? 'RECOVERED' : 'CLOSED_LOSS';
    $reason=$id === 107 ? 'OTHER_CLOSURE' : 'RECOVERY_WINDOW_EXPIRED_D90';
    $seed->execute([$id,$tenant,1,$state,$reason,'RECEIVED_OK',
        '2026-07-01 00:00:00','2026-11-08 10:00:00',null,'2026-11-08 10:00:00',
        '35.00','15.38',null,'SUPPORT-EXISTING']);
}
$event=$db->prepare('INSERT INTO amazon_return_events
    (tenant_id,amazon_connection_id,case_id,event_type,source,source_event_id,
     idempotency_key,occurred_at,payload_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
foreach ([101,103,104,105,106,107] as $id) {
    $event->execute([$id === 105 ? 2 : 1,1,$id,'PHYSICAL_RECEIVED','WAREHOUSE',
        'warehouse-'.$id,hash('sha256','warehouse-'.$id),
        '2026-07-07 00:00:00','{}','2026-07-07 00:00:00']);
}
$event->execute([1,1,103,'CASE_CLOSED_ADMIN_90D','INTERNAL','closure-103',
    hash('sha256','closure-103'),'2026-10-08 12:00:00','{}','2026-10-08 12:00:00']);
// Caso 108 possui somente evento de outro tenant. Nao aceitá-lo como prova.
$event->execute([2,1,108,'PHYSICAL_RECEIVED','WAREHOUSE','warehouse-other-108',
    hash('sha256','warehouse-other-108'),'2026-07-07 00:00:00','{}','2026-07-07 00:00:00']);
$db->exec("INSERT INTO amazon_return_outbox
    (tenant_id,amazon_connection_id,case_id,status,kind)
    VALUES (1,1,104,'PENDING','SAFE_T_READ')");

$repair=new SvAmazonD90WarehouseClosureRepair(
    SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,1))
);
$now=new DateTimeImmutable('2026-11-09 04:50:00',new DateTimeZone('UTC'));
d90wrSame('CORRECTED',$repair->repairOne(101,$now)['status'],'Confirmed warehouse case must be corrected.');
$row=$db->query('SELECT * FROM amazon_return_cases WHERE id=101')->fetch(PDO::FETCH_ASSOC);
d90wrSame('RECEIVED_OK',$row['state'],'Canonical state after correction.');
d90wrSame(null,$row['terminal_reason'],'Do not retain false terminal classification.');
d90wrSame(null,$row['closed_at'],'Wrong financial closure must be cleared.');
d90wrSame(null,$row['next_action_at'],'D+90 must not schedule a new SAFE-T attempt.');
d90wrSame('15.38',$row['reconciled_credit_amount'],'Financial credit remains untouched.');
d90wrSame('35.00',$row['expected_reimbursement_amount'],'Expected amount remains untouched.');
d90wrSame('SUPPORT-EXISTING',$row['support_case_id'],'Existing support case remains untouched.');
d90wrSame('2026-11-09 04:50:00',$row['updated_at'],'Correction uses actual UTC clock.');
$log=$db->query("SELECT occurred_at,payload_json FROM amazon_return_events
    WHERE case_id=101 AND event_type='CASE_D90_WAREHOUSE_CLOSURE_CORRECTED'")->fetch(PDO::FETCH_ASSOC);
d90wrSame('2026-11-09 04:50:00',$log['occurred_at'],'Event must not be backdated.');
$payload=json_decode((string)$log['payload_json'],true,512,JSON_THROW_ON_ERROR);
d90wrSame('2026-11-08 10:00:00',$payload['previous_closed_at'],'Preserve prior closure for review.');
d90wrSame('RECOVERY_WINDOW_EXPIRED_D90',$payload['previous_terminal_reason'],'Preserve original reason.');
d90wrSame('SKIPPED',$repair->repairOne(101,$now)['status'],'Replay must be idempotent.');
foreach ([102=>'warehouse_proof_absent',103=>'original_audit_present',104=>'active_outbox',
          106=>'preconditions_not_met',107=>'preconditions_not_met',108=>'warehouse_proof_absent'] as $id=>$why) {
    $r=$repair->repairOne($id,$now);
    d90wrSame('SKIPPED',$r['status'],'Guard for '.$id);
    d90wrSame($why,$r['reason'],'Guard reason for '.$id);
    d90wrSame($id === 106 ? 'RECOVERED' : 'CLOSED_LOSS',
        $db->query('SELECT state FROM amazon_return_cases WHERE id='.$id)->fetchColumn(),
        'No state mutation for '.$id);
}
try {
    $repair->repairOne(105,$now);
    throw new RuntimeException('Cross-tenant repair was not rejected.');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Cross-tenant repair was not rejected.') throw $e;
}
d90wrSame(1,(int)$db->query("SELECT COUNT(*) FROM amazon_return_events
    WHERE event_type='CASE_D90_WAREHOUSE_CLOSURE_CORRECTED'")->fetchColumn(),
    'Only one compensating event must be written.');
echo "d90-warehouse-closure-repair-test: OK\n";
