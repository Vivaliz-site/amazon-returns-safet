<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__ . '/../includes/amazon-returns/RecoveryWindow.php';
require_once __DIR__ . '/../includes/amazon-returns/AgeClosure.php';

function ageAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function ageSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException(
        $message . ': expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
    );
}

// Emulate row locks in SQLite while verifying the same scoped repository path.
final class AgeClosureSqlitePdo extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$db = new AgeClosureSqlitePdo('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('UTC_TIMESTAMP', static fn(): string => '2026-10-11 12:00:00', 0);
$db->exec("CREATE TABLE amazon_return_cases (
  id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, amazon_connection_id INTEGER NOT NULL,
  amazon_order_id TEXT NOT NULL, state TEXT NOT NULL, physical_status TEXT NOT NULL,
  quantity_received INTEGER NOT NULL DEFAULT 0, refund_at TEXT, closed_at TEXT,
  terminal_reason TEXT, next_action_at TEXT, expected_reimbursement_amount TEXT NOT NULL,
  reconciled_credit_amount TEXT NOT NULL, updated_at TEXT
)");
$db->exec("CREATE TABLE amazon_return_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, amazon_connection_id INTEGER NOT NULL,
  case_id INTEGER NOT NULL, event_type TEXT NOT NULL, source TEXT NOT NULL, source_event_id TEXT,
  idempotency_key TEXT NOT NULL UNIQUE, occurred_at TEXT NOT NULL, payload_json TEXT NOT NULL,
  evidence_sha256 TEXT, created_at TEXT NOT NULL
)");
$db->exec("CREATE TABLE amazon_return_outbox (
  id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, amazon_connection_id INTEGER NOT NULL,
  case_id INTEGER NOT NULL, status TEXT NOT NULL, kind TEXT NOT NULL
)");
$seed = $db->prepare("INSERT INTO amazon_return_cases
 (id,tenant_id,amazon_connection_id,amazon_order_id,state,physical_status,quantity_received,refund_at,closed_at,
  terminal_reason,next_action_at,expected_reimbursement_amount,reconciled_credit_amount,updated_at)
 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$now = new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('UTC'));
$days = static fn(int $n): string => $now->sub(new DateInterval('P'.$n.'D'))->format('Y-m-d H:i:s');
$add = static function(int $id, string $state, ?string $refund, string $physical='NOT_RECEIVED',
    int $received=0, int $tenant=1, string $closed='') use ($seed): void {
    $seed->execute([$id,$tenant,1,'702-test-'.$id,$state,$physical,$received,$refund,
        $closed !== '' ? $closed : null, null,'2026-10-16 12:00:00','100.00','25.00','2026-10-11 12:00:00']);
};
$add(101,'CREDIT_PENDING',$days(91));
$add(102,'SUPPORT_ESCALATION',$days(90));
$add(103,'CREDIT_PENDING',$days(89));
$add(104,'RECEIVED_OK',$days(120),'RECEIVED_OK',1);
$add(105,'CREDIT_PENDING',$days(120),'RECEIVED_OK',1);
$add(106,'CREDIT_PENDING',$days(120),'RECEIVED_DISCREPANT',1);
$add(107,'CREDIT_PENDING',$days(120));
$add(108,'CREDIT_PENDING',$days(120),'NOT_RECEIVED',0,2);
$add(109,'RECOVERED',$days(120),'NOT_RECEIVED',0,1,'2026-09-01 00:00:00');
$add(110,'CLOSED_LOSS',$days(120),'NOT_RECEIVED',0,1,'2026-09-01 00:00:00');
$add(111,'CREDIT_PENDING',$days(120));
$add(112,'CREDIT_PENDING',null);
$add(113,'CREDIT_PENDING',$days(120));
$db->exec("INSERT INTO amazon_return_outbox (tenant_id,amazon_connection_id,case_id,status,kind)
 VALUES (1,1,107,'PENDING','SAFE_T_APPEAL'),(1,1,113,'PROCESSING','SELLER_SUPPORT_UPDATE')");
$db->exec("INSERT INTO amazon_return_events
 (tenant_id,amazon_connection_id,case_id,event_type,source,source_event_id,idempotency_key,occurred_at,payload_json,created_at)
 VALUES (1,1,111,'PHYSICAL_RECEIVED','WAREHOUSE','warehouse-111','" . hash('sha256','warehouse-111') . "','2026-09-01 12:00:00','{}','2026-09-01 12:00:00')");

$scope = SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,1));
$closer = new SvAmazonAgeClosure($scope);
$first = $closer->run($now);
ageSame(2,$first['closed'],'Only eligible unrecovered age 90+ cases must close.');
ageSame('150.00',$first['unrecovered_brl'],'Closing must account for actual reconciled partial credits.');
ageSame(2,$first['deferred_active_outbox'],'Active external writes block premature closure.');
foreach([101,102] as $id){
    $row=$db->query('SELECT * FROM amazon_return_cases WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
    ageSame('CLOSED_LOSS',$row['state'],'Case '.$id.' should close.');
    ageSame('OWNER_ADMINISTRATIVE_CLOSURE_AFTER_90D',$row['terminal_reason'],'Closure must be differentiated from recovery.');
    ageSame('2026-10-11 12:00:00',$row['closed_at'],'Closing uses deterministic UTC clock.');
    ageSame(null,$row['next_action_at'],'Pending retries must be cleared.');
    ageSame('25.00',$row['reconciled_credit_amount'],'Partial credit must not be erased.');
}
foreach([103,104,105,106,107,108,111,112,113] as $id){
    $state=(string)$db->query('SELECT state FROM amazon_return_cases WHERE id='.$id)->fetchColumn();
    ageAssert($state!=='CLOSED_LOSS','Case '.$id.' must not be closed.');
}
ageSame('2026-09-01 00:00:00',$db->query('SELECT closed_at FROM amazon_return_cases WHERE id=110')->fetchColumn(),'Old terminal timestamp stays stable.');
ageSame(2,(int)$db->query("SELECT COUNT(*) FROM amazon_return_events WHERE event_type='CASE_CLOSED_ADMIN_90D'")->fetchColumn(),'One event per closure.');
$event=$db->query("SELECT payload_json FROM amazon_return_events WHERE case_id=101 AND event_type='CASE_CLOSED_ADMIN_90D'")->fetchColumn();
$payload=json_decode((string)$event,true,512,JSON_THROW_ON_ERROR);
ageSame('75.00',$payload['unrecovered_amount_brl'],'The audit event records net loss, not full buyer refund.');
ageSame('25.00',$payload['credit_confirmed_amount_brl'],'Audited reconciled credit must be preserved.');
$second=$closer->run($now);
ageSame(0,$second['closed'],'Rerun must be idempotent.');
ageSame(2,(int)$db->query("SELECT COUNT(*) FROM amazon_return_events WHERE event_type='CASE_CLOSED_ADMIN_90D'")->fetchColumn(),'No duplicate audit event.');
// A late worker must not reopen an already closed administrative loss.
$repository = new SvAmazonReturnCaseRepository($db,new SvAmazonTenantContext(1,1));
$repository->update(101,['state'=>'EMAIL_REVIEW_SENT','closed_at'=>null,'terminal_reason'=>null]);
$stillClosed = $db->query('SELECT state,closed_at,terminal_reason FROM amazon_return_cases WHERE id=101')
    ->fetch(PDO::FETCH_ASSOC);
ageSame('CLOSED_LOSS',$stillClosed['state'],'Late email worker may not reopen a closed loss.');
ageSame('2026-10-11 12:00:00',$stillClosed['closed_at'],'Late worker may not clear closure timestamp.');
ageSame('OWNER_ADMINISTRATIVE_CLOSURE_AFTER_90D',$stillClosed['terminal_reason'],'Late worker may not erase closure reason.');
$repository->update(103,['state'=>'SUPPORT_ESCALATION']);
ageSame('SUPPORT_ESCALATION',$db->query('SELECT state FROM amazon_return_cases WHERE id=103')->fetchColumn(),
    'Open cases must remain freely updatable by authorized workers.');

$db->exec("UPDATE amazon_return_outbox SET status='SUCCEEDED' WHERE case_id=107");
$third=$closer->run($now);
ageSame(1,$third['closed'],'Case deferred by pending write should close after write finishes.');
echo "automatic-90-day-closure-test: OK\n";
