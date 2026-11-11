<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/TenantContext.php';
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';

function priorityCheck(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}

final class DiscoveryPriorityDb extends PDO {
    public array $executed=[];
    public array $ids=[13210,1263,13210,0,'not-an-id'];
    public function __construct(){}
    private bool $transaction=false;
    public function beginTransaction():bool {$this->transaction=true;return true;}
    public function commit():bool {$this->transaction=false;return true;}
    public function rollBack():bool {$this->transaction=false;return true;}
    public function inTransaction():bool {return $this->transaction;}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        return new DiscoveryPriorityStmt($this,$query);
    }
}
final class DiscoveryPriorityStmt extends PDOStatement {
    public function __construct(private DiscoveryPriorityDb $db,private string $sql){}
    public function execute(?array $params=null):bool {
        $this->db->executed[]=['sql'=>$this->sql,'params'=>$params??[]];
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        return $mode===PDO::FETCH_COLUMN?$this->db->ids:[];
    }
}

$db=new DiscoveryPriorityDb();
$outbox=new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,1));
priorityCheck(method_exists($outbox,'urgentDiscoveryCaseIds'),'RED: D+45 discovery priority is absent.');
$ids=$outbox->urgentDiscoveryCaseIds(999);
priorityCheck($ids===[13210,1263],'Urgency IDs must be valid, ordered, unique and scoped.');
$query=$db->executed[0]['sql']??'';
$params=$db->executed[0]['params']??[];
foreach([
    "o.tenant_id=:tenant_id","o.amazon_connection_id=:amazon_connection_id",
    "c.tenant_id=o.tenant_id","c.amazon_connection_id=o.amazon_connection_id",
    "o.kind='SAFE_T_DISCOVERY'", "o.status='PENDING'",
    "o.status='PROCESSING'", "locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND)",
    "o.available_at<=UTC_TIMESTAMP()",
    "c.closed_at IS NULL", "c.safe_t_id IS NULL", "c.quantity_received=0",
    "c.refund_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)",
    "c.refund_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 45 DAY)",
    "ORDER BY c.refund_at ASC", "LIMIT 50",
] as $expected) {
    priorityCheck(str_contains($query,$expected),'Missing D+45 priority invariant: '.$expected);
}
priorityCheck(($params[':tenant_id']??null)===1
    && ($params[':amazon_connection_id']??null)===1,
    'Priority selection must preserve tenant/connection isolation.');
// Regression: claimBatch's traditional due-date ordering previously discarded
// urgentDiscoveryCaseIds()'s D+90 order. The real transactional claimant must
// preserve the provided priority when, and only when, requested.
$outbox->claimBatch(1,['SAFE_T_DISCOVERY'],[13210,1263],true);
$scopedQuery=$db->executed[array_key_last($db->executed)]['sql']??'';
priorityCheck(str_contains($scopedQuery,
    'ORDER BY FIELD(case_id,13210,1263),available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED'),
    'D+90 priority was lost when the scoped transactional claim re-sorted by available_at.');
priorityCheck(str_contains($scopedQuery,'case_id IN (:allowed_case_0,:allowed_case_1)')
    && str_contains($scopedQuery,'FOR UPDATE SKIP LOCKED'),
    'Urgent claim must keep the existing scoped and fenced transactional path.');
$scopedParams=$db->executed[array_key_last($db->executed)]['params']??[];
priorityCheck(($scopedParams[':allowed_case_0']??null)===13210
    && ($scopedParams[':allowed_case_1']??null)===1263,
    'Urgent claim must bind only the two authorized case IDs.');
$outbox->claimBatch(1,['SAFE_T_DISCOVERY'],[13210,1263]);
$ordinaryQuery=$db->executed[array_key_last($db->executed)]['sql']??'';
priorityCheck(str_contains($ordinaryQuery,'ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED')
    && !str_contains($ordinaryQuery,'FIELD(case_id,'),
    'Non-urgent claims must preserve their original order and lease semantics.');

priorityCheck(!preg_match('/\\b(UPDATE|DELETE|INSERT|FOR UPDATE)\\b/i',$query),
    'Priority probe must never mutate or claim pending jobs.');
priorityCheck(str_contains(($db->executed[0]['sql']??''),'SELECT o.case_id'),
    'Priority probe must not access credentials or payloads.');

$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
$begin=strpos($source,'public function pull(DateTimeImmutable $now)');
$end=strpos($source,'public function acceptResult(', $begin===false?0:$begin);
priorityCheck($begin!==false && $end!==false && $end>$begin,'Read-only pull flow missing.');
$pull=substr($source,$begin,$end-$begin);
$known=strpos($pull,"claimBatch(1,['SAFE_T_READ','SELLER_SUPPORT_READ'])");
$delivery=strpos($pull,"claimBatch(1,['ORDER_DELIVERY_READ'])");
$urgent=strpos($pull,'urgentDiscoveryCaseIds(50)');
$scoped=strpos($pull,"claimBatch(1,['SAFE_T_DISCOVERY'],\$urgentIds,true)");
$fallback=strpos($pull,"claimBatch(1,['SAFE_T_DISCOVERY'])");
priorityCheck($known!==false && $delivery!==false && $urgent!==false
    && $scoped!==false && $fallback!==false
    && $known<$delivery && $delivery<$urgent && $urgent<$scoped && $scoped<$fallback,
    'Existing known-claim, support and delivery lanes must remain ahead of urgency and normal fallback.');
priorityCheck(!str_contains($pull,'SAFE_T_SUBMIT') && !str_contains($pull,'SELLER_SUPPORT_OPEN'),
    'Discovery priority must never dispatch an external write action.');
echo "safe-t-discovery-d45-priority-test: OK\\n";
