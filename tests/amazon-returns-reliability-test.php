<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Outbox.php';
require_once __DIR__ . '/../includes/amazon-returns/FinancialReconciler.php';
require_once __DIR__ . '/../workers/amazon-returns/scheduler.php';
require_once __DIR__ . '/../workers/amazon-returns/reconcile.php';

function rlSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function rlAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

final class ReliabilityMemoryPdo extends PDO {
    public array $outbox = [];
    public int $nextId = 1;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new ReliabilityMemoryStatement($this, $query); }
    public function lastInsertId(?string $name = null): string|false { return (string)($this->nextId - 1); }
}
final class ReliabilityMemoryStatement extends PDOStatement {
    private array $result = [];
    private int $cursor = 0;
    public function __construct(private ReliabilityMemoryPdo $db, private string $query) {}
    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = strtoupper($this->query);
        if (str_starts_with(ltrim($sql), 'INSERT INTO AMAZON_RETURN_OUTBOX')) {
            foreach ($this->db->outbox as $row) {
                if ($row['idempotency_key'] === $params[':idempotency_key']) throw new PDOException('Duplicate entry', 23000);
            }
            $id = $this->db->nextId++;
            $this->db->outbox[$id] = ['id'=>$id,'case_id'=>$params[':case_id'],'kind'=>$params[':kind'],'idempotency_key'=>$params[':idempotency_key'],'payload_json'=>$params[':payload_json'],'status'=>'PENDING','attempt_count'=>0];
            return true;
        }
        if (str_contains($sql, 'FROM AMAZON_RETURN_OUTBOX') && str_contains($sql, 'IDEMPOTENCY_KEY')) {
            foreach ($this->db->outbox as $row) if ($row['idempotency_key'] === $params[':idempotency_key']) $this->result[] = ['id'=>$row['id']];
            return true;
        }
        if (str_starts_with(ltrim($sql), 'UPDATE AMAZON_RETURN_OUTBOX') && str_contains($sql, "STATUS='PENDING'")) {
            $id = (int)$params[':id'];
            $this->db->outbox[$id]['status'] = 'PENDING';
            $this->db->outbox[$id]['attempt_count'] = max(0, (int)$this->db->outbox[$id]['attempt_count'] - 1);
            return true;
        }
        throw new LogicException('Unexpected reliability SQL: ' . $this->query);
    }
    public function fetchColumn(int $column = 0): mixed { $row = $this->result[$this->cursor++] ?? false; return is_array($row) ? array_values($row)[$column] : false; }
}

$db = new ReliabilityMemoryPdo();
$key = SvAmazonReturnsOutbox::deterministicKey('SAFE_T_SUBMIT', 77, 'policy-12|2026-07-16');
$first = SvAmazonReturnsOutbox::enqueue($db, 'SAFE_T_SUBMIT', 77, ['order_id'=>'702-1234567-7654321'], $key);
$second = SvAmazonReturnsOutbox::enqueue($db, 'SAFE_T_SUBMIT', 77, ['order_id'=>'different-payload-ignored-by-key'], $key);
rlSame($first, $second, 'Duplicate enqueue must resolve existing outbox ID.');
rlSame(1, count($db->outbox), 'Duplicate enqueue must not create second row.');

$now = new DateTimeImmutable('2026-09-01T12:00:00Z');
$retry = SvAmazonReturnsOutbox::retryDecision(['attempt_count'=>1,'payload'=>[]], $now);
rlSame('RETRY', $retry['status'], 'Early failure must retry.');
rlAssert($retry['next_at'] > $now, 'Retry must be scheduled in the future.');
$exhausted = SvAmazonReturnsOutbox::retryDecision(['attempt_count'=>5,'payload'=>[]], $now);
rlSame('DEAD_LETTER', $exhausted['status'], 'Max attempts must go to DLQ.');
$deadline = SvAmazonReturnsOutbox::retryDecision(['attempt_count'=>1,'payload'=>['deadline_at'=>'2026-09-01T12:00:30Z']], $now);
rlSame('DEAD_LETTER', $deadline['status'], 'Retry that would miss deadline must DLQ/alert instead of silently expiring.');
rlSame(true, SvAmazonReturnsOutbox::leaseExpired('2026-09-01T11:50:00Z', $now), 'Stale processing lease must be reclaimable after restart.');
rlSame(false, SvAmazonReturnsOutbox::leaseExpired('2026-09-01T11:59:00Z', $now), 'Fresh processing lease must not be stolen.');
$db->outbox[$first]['status'] = 'PROCESSING';
$db->outbox[$first]['attempt_count'] = 2;
SvAmazonReturnsOutbox::releaseUnprocessed($db, $first);
rlSame('PENDING', $db->outbox[$first]['status'], 'Disabled write must return to pending without consuming the action.');
rlSame(1, $db->outbox[$first]['attempt_count'], 'Release must undo the claim attempt count.');

$reconciler = new SvAmazonFinancialReconciler();
$case = ['state'=>'SAFE_T_APPROVED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00'];
$none = $reconciler->reconcile($case, []);
rlSame('CREDIT_PENDING', $none['state'], 'Approved SAFE-T without financial credit is CREDIT_PENDING.');
rlSame('100.00', $none['outstanding_amount'], 'No credit leaves full amount outstanding.');
$partial = $reconciler->reconcile($case, [['seller_effect_amount'=>'40.00','transaction_id'=>'credit-1']]);
rlSame('CREDIT_PENDING', $partial['state'], 'Partial credit stays pending.');
rlSame('40.00', $partial['credit_amount'], 'Partial credit amount.');
rlSame('60.00', $partial['outstanding_amount'], 'Partial outstanding amount.');
$full = $reconciler->reconcile($case, [['seller_effect_amount'=>'100.00','transaction_id'=>'credit-2']]);
rlSame('RECOVERED', $full['state'], 'Matching credit closes recovery.');
$duplicateCredit = $reconciler->reconcile($case, [
    ['transaction_id'=>'credit-deferred','transaction_type'=>'FBAInventoryReimbursement','transaction_status'=>'DEFERRED_RELEASED','posted_at'=>'2026-09-01T10:00:00Z','total_amount'=>['amount'=>'40.00','currency'=>'BRL']],
    ['transaction_id'=>'credit-released','transaction_type'=>'FBAInventoryReimbursement','transaction_status'=>'RELEASED','posted_at'=>'2026-09-03T10:00:00Z','total_amount'=>['amount'=>'40.00','currency'=>'BRL']],
]);
rlSame('40.00', $duplicateCredit['credit_amount'], 'Deferred->released reimbursement lifecycle must count once economically.');
rlSame(['credit-deferred','credit-released'], $duplicateCredit['transaction_ids'], 'Both credit lifecycle transaction IDs remain audit evidence.');
$reversed = $reconciler->reconcile(['state'=>'RECOVERED','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'100.00'], [
    ['seller_effect_amount'=>'100.00','transaction_id'=>'credit-2'],
    ['seller_effect_amount'=>'-100.00','transaction_id'=>'reversal-1'],
]);
rlSame('CREDIT_PENDING', $reversed['state'], 'Later reversal must reopen exposure.');
rlSame(true, $reversed['reopened'], 'Reversal of recovered case must be flagged reopened.');

$decision = ['action'=>'WAIT','reason'=>'test'];
rlSame(false, SvAmazonReturnsScheduler::isWriteAction($decision), 'WAIT is not an external write.');
rlSame(true, SvAmazonReturnsScheduler::isWriteAction(['action'=>'SELLER_SUPPORT_OPEN']), 'Support open is an outbox write.');
rlSame(true, SvAmazonReturnsScheduler::isWriteAction(['action'=>'SAFE_T_EMAIL_REVIEW']), 'Denied appeal email review is an outbox write.');
rlSame('gmail', SvAmazonReturnsScheduler::dependencyForAction('SAFE_T_EMAIL_REVIEW'), 'Detailed review email depends on Gmail, not Seller Central browser.');
rlSame('seller_central_bridge', SvAmazonReturnsScheduler::dependencyForAction('SAFE_T_APPEAL'), 'SAFE-T appeal depends on Seller Central browser.');

echo "amazon-returns-reliability-test: OK\n";
