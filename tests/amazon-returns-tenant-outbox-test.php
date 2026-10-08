<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantOutbox.php';

function toAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function toSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function toThrows(callable $operation, string $message): void
{
    try { $operation(); } catch (InvalidArgumentException|RuntimeException) { return; }
    throw new RuntimeException($message);
}

function outboxDuplicate(): PDOException
{
    $exception = new PDOException('Duplicate scoped outbox key', 23000);
    $exception->errorInfo = ['23000',1062,'Duplicate scoped outbox key'];
    return $exception;
}

final class TenantOutboxMemoryPdo extends PDO
{
    /** @var list<array<string,mixed>> */
    public array $responses = [];
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $executed = [];
    public string $lastId = '301';
    private bool $transaction = false;

    public function __construct() {}

    /** @param array<string,mixed> $response */
    public function queue(array $response): void
    {
        $this->responses[] = $response;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TenantOutboxMemoryStatement($this, $query, array_shift($this->responses) ?? []);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastId;
    }

    public function beginTransaction(): bool
    {
        if ($this->transaction) throw new RuntimeException('Nested test transaction.');
        $this->transaction = true;
        return true;
    }
    public function commit(): bool
    {
        if (!$this->transaction) return false;
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->transaction) return false;
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }
}

final class TenantOutboxMemoryStatement extends PDOStatement
{
    /** @param array<string,mixed> $response */
    public function __construct(
        private TenantOutboxMemoryPdo $db,
        private string $sql,
        private array $response
    ) {}

    public function execute(?array $params = null): bool
    {
        $this->db->executed[] = ['sql'=>$this->sql, 'params'=>$params ?? []];
        if (($this->response['throw'] ?? null) instanceof Throwable) throw $this->response['throw'];
        return (bool)($this->response['execute'] ?? true);
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->response['fetch'] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return is_array($this->response['rows'] ?? null) ? $this->response['rows'] : [];
    }

    public function rowCount(): int
    {
        return (int)($this->response['row_count'] ?? 0);
    }
}

$db = new TenantOutboxMemoryPdo();
$context = new SvAmazonTenantContext(1, 10);
$outbox = new SvAmazonTenantReturnsOutbox($db, $context);
$db->queue(['row_count'=>3]);
toAssert(method_exists($outbox,'supersedeTerminalReadJobs'),'Outbox must be able to clear obsolete Seller Central read jobs after a case closes.');
toSame(3,$outbox->supersedeTerminalReadJobs('TERMINAL_CASE_NO_LONGER_REQUIRES_READ'),'Closed-case read sweep must report the number of superseded jobs.');
$terminalSweep=$db->executed[array_key_last($db->executed)]['sql']??'';
toAssert(str_contains($terminalSweep,'closed_at IS NOT NULL'),'Terminal read sweep must only target concluded cases.');
foreach(['SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ'] as $readKind){
    toAssert(str_contains($terminalSweep,$readKind),'Terminal read sweep must include '.$readKind.'.');
}
toAssert(!str_contains($terminalSweep,'SAFE_T_SUBMIT'),'Terminal read sweep must never touch external write kinds.');
toAssert(str_contains($terminalSweep, "o.status='PENDING' OR (o.status='PROCESSING' AND o.locked_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 300 SECOND))"), 'Terminal read sweep must keep a live PROCESSING lease and only supersede stale processing reads.');
$db->queue(['row_count'=>2]);
toAssert(method_exists($outbox,'supersedeDiscoveryJobsForKnownSafeT'),'Outbox must clear discovery jobs once SAFE-T identity is known.');
toSame(2,$outbox->supersedeDiscoveryJobsForKnownSafeT('SAFE_T_ID_ALREADY_KNOWN'),'Known SAFE-T sweep must report superseded stale discovery jobs.');
$knownSafeTSweep=$db->executed[array_key_last($db->executed)]['sql']??'';
toAssert(str_contains($knownSafeTSweep, "o.kind='SAFE_T_DISCOVERY'"),'Known SAFE-T sweep must only target discovery jobs.');
toAssert(str_contains($knownSafeTSweep, "c.safe_t_id IS NOT NULL"),'Known SAFE-T sweep must require a persisted SAFE-T identity.');
toAssert(!str_contains($knownSafeTSweep,'SAFE_T_READ'),'Known SAFE-T sweep must preserve ordinary claim reads.');
$key = $outbox->deterministicKey('SAFE_T_SUBMIT', 77, 'policy-12|2026-07-16');
toSame(
    hash('sha256', 'tenant:1|connection:10|SAFE_T_SUBMIT|77|policy-12|2026-07-16'),
    $key,
    'Outbox deterministic key must include tenant and connection scope.'
);

$db->queue(['fetch'=>['id'=>77]]);
$db->queue([]);
$first = $outbox->enqueue('SAFE_T_SUBMIT', 77, ['order_id'=>'702-1234567-7654321'], $key);
toSame(301, $first, 'Outbox insert must return generated ID.');
$db->queue(['fetch'=>['id'=>77]]);
$db->queue(['throw'=>outboxDuplicate()]);
$db->queue(['fetch'=>['id'=>301]]);
$duplicate = $outbox->enqueue('SAFE_T_SUBMIT', 77, ['order_id'=>'ignored'], $key);
toSame($first, $duplicate, 'Scoped duplicate outbox action must resolve locally.');

$reviveDb = new TenantOutboxMemoryPdo();
$reviveOutbox = new SvAmazonTenantReturnsOutbox($reviveDb, $context);
$reviveKey = $reviveOutbox->deterministicKey('SELLER_SUPPORT_OPEN', 77, 'expired-appeal-recovery');
$reviveDb->queue(['fetch'=>['id'=>77]]);
$reviveDb->queue(['throw'=>outboxDuplicate()]);
$reviveDb->queue(['fetch'=>[
    'id'=>404,'status'=>'SUPERSEDED','attempt_count'=>0,'kind'=>'SELLER_SUPPORT_OPEN','case_id'=>77,
]]);
$reviveDb->queue(['row_count'=>1]);
toAssert(method_exists($reviveOutbox, 'enqueueResult'), 'Outbox must report whether idempotent scheduling actually created active work.');
$revivedResult = $reviveOutbox->enqueueResult('SELLER_SUPPORT_OPEN', 77, ['order_id'=>'702-7654321-1234567'], $reviveKey);
toSame(404, $revivedResult['id'] ?? null, 'An unattempted superseded action must be reusable when the exact decision becomes current again.');
toSame(true, $revivedResult['enqueued'] ?? null, 'Reactivating an unattempted superseded action must count as newly enqueued work.');
$reviveSql = $reviveDb->executed[array_key_last($reviveDb->executed)]['sql'] ?? '';
toAssert(str_contains($reviveSql, "SET status='PENDING'"), 'Reusing an unattempted superseded action must reactivate it.');
toAssert(str_contains($reviveSql, "status='SUPERSEDED'"), 'Reactivation must only target a superseded row.');
toAssert(str_contains($reviveSql, 'attempt_count=0'), 'Reactivation must never revive an action that may already have reached an external system.');
$reviveParams = $reviveDb->executed[array_key_last($reviveDb->executed)]['params'] ?? [];
toSame('{"order_id":"702-7654321-1234567"}', $reviveParams[':payload_json'] ?? null, 'Reactivated action must use the current write snapshot/payload.');

$attemptedDb = new TenantOutboxMemoryPdo();
$attemptedOutbox = new SvAmazonTenantReturnsOutbox($attemptedDb, $context);
$attemptedDb->queue(['fetch'=>['id'=>77]]);
$attemptedDb->queue(['throw'=>outboxDuplicate()]);
$attemptedDb->queue(['fetch'=>[
    'id'=>405,'status'=>'SUPERSEDED','attempt_count'=>1,'kind'=>'SELLER_SUPPORT_OPEN','case_id'=>77,
]]);
$attemptedResult = $attemptedOutbox->enqueueResult('SELLER_SUPPORT_OPEN', 77, ['order_id'=>'702-7654321-1234567'], $reviveKey);
toSame(405, $attemptedResult['id'] ?? null, 'An attempted superseded action must remain idempotently addressable.');
toSame(false, $attemptedResult['enqueued'] ?? null, 'An attempted superseded action must not be reported as newly enqueued.');
$attemptedReactivation = array_values(array_filter(
    $attemptedDb->executed,
    static fn(array $execution): bool => str_contains((string)($execution['sql'] ?? ''), "SET status='PENDING'")
));
toSame([], $attemptedReactivation, 'A superseded action with any prior attempt must never be reactivated automatically.');

$scopeDb = new TenantOutboxMemoryPdo();
$scopeOutbox = new SvAmazonTenantReturnsOutbox($scopeDb, $context);
$scopeKey = $scopeOutbox->deterministicKey('SELLER_SUPPORT_UPDATE', 77, 'support-scope-22449931941');
$scopePayload = ['order_id'=>'702-9207715-8524262','support_case_id'=>'22449931941'];
$scopeDb->queue(['fetch'=>['id'=>77]]);
$scopeDb->queue(['throw'=>outboxDuplicate()]);
$scopeDb->queue(['fetch'=>[
    'id'=>406,'status'=>'SUPERSEDED','attempt_count'=>1,'kind'=>'SELLER_SUPPORT_UPDATE','case_id'=>77,
    'last_error'=>'SELLER_SUPPORT_SCOPE_SUPERSEDED',
    'payload_json'=>'{"order_id":"702-9207715-8524262","support_case_id":"22449931941"}',
]]);
$scopeDb->queue(['row_count'=>1]);
$scopeRevived = $scopeOutbox->enqueueResult('SELLER_SUPPORT_UPDATE',77,$scopePayload,$scopeKey);
toSame(406,$scopeRevived['id']??null,'A scope-superseded Seller Support update must keep its original outbox identity.');
toSame(true,$scopeRevived['enqueued']??null,'A scope-superseded update must be rearmed when the exact same support scope becomes current again.');
$scopeReactivation = array_values(array_filter(
    $scopeDb->executed,
    static fn(array $execution): bool => str_contains((string)($execution['sql']??''),"SET status='PENDING'")
));
toSame(1,count($scopeReactivation),'Scope recovery must perform exactly one guarded reactivation.');
toAssert(str_contains((string)($scopeReactivation[0]['sql']??''),"last_error='SELLER_SUPPORT_SCOPE_SUPERSEDED'"),'Scope recovery SQL must be guarded by the exact pre-write supersede reason.');

$scopeMismatchDb = new TenantOutboxMemoryPdo();
$scopeMismatchOutbox = new SvAmazonTenantReturnsOutbox($scopeMismatchDb,$context);
$scopeMismatchDb->queue(['fetch'=>['id'=>77]]);
$scopeMismatchDb->queue(['throw'=>outboxDuplicate()]);
$scopeMismatchDb->queue(['fetch'=>[
    'id'=>407,'status'=>'SUPERSEDED','attempt_count'=>1,'kind'=>'SELLER_SUPPORT_UPDATE','case_id'=>77,
    'last_error'=>'SELLER_SUPPORT_SCOPE_SUPERSEDED',
    'payload_json'=>'{"order_id":"702-9207715-8524262","support_case_id":"22450149561"}',
]]);
$scopeMismatch = $scopeMismatchOutbox->enqueueResult('SELLER_SUPPORT_UPDATE',77,$scopePayload,$scopeKey);
toSame(false,$scopeMismatch['enqueued']??null,'A superseded update from a different Seller Support scope must remain inert.');
$scopeMismatchReactivation = array_values(array_filter(
    $scopeMismatchDb->executed,
    static fn(array $execution): bool => str_contains((string)($execution['sql']??''),"SET status='PENDING'")
));
toSame([],$scopeMismatchReactivation,'Scope recovery must never reactivate a different support thread.');

$legacyFalseSuccessDb = new TenantOutboxMemoryPdo();
$legacyFalseSuccessOutbox = new SvAmazonTenantReturnsOutbox($legacyFalseSuccessDb,$context);
$legacyFalseSuccessKey = $legacyFalseSuccessOutbox->deterministicKey('SELLER_SUPPORT_UPDATE',77,'legacy-unconfirmed-update');
$legacyFalseSuccessPayload = ['order_id'=>'702-6823050-9173862','support_case_id'=>'22426419421'];
$legacyFalseSuccessDb->queue(['fetch'=>['id'=>77]]);
$legacyFalseSuccessDb->queue(['throw'=>outboxDuplicate()]);
$legacyFalseSuccessDb->queue(['fetch'=>[
    'id'=>408,'status'=>'SUCCEEDED','attempt_count'=>1,'kind'=>'SELLER_SUPPORT_UPDATE','case_id'=>77,
    'last_error'=>null,
    'payload_json'=>'{"order_id":"702-6823050-9173862","support_case_id":"22426419421"}',
]]);
$legacyFalseSuccessDb->queue(['fetch'=>[
    'payload_json'=>'{"action":"SELLER_SUPPORT_UPDATE","status":"ALREADY_EXISTS","reason":null,"submitted":false,"external_id":"22426419421"}',
]]);
$legacyFalseSuccessDb->queue(['row_count'=>1]);
$legacyFalseSuccess = $legacyFalseSuccessOutbox->enqueueResult(
    'SELLER_SUPPORT_UPDATE',77,$legacyFalseSuccessPayload,$legacyFalseSuccessKey
);
toSame(408,$legacyFalseSuccess['id']??null,'A legacy false-success Seller Support update must preserve its outbox identity.');
toSame(true,$legacyFalseSuccess['enqueued']??null,'A legacy SUCCEEDED update backed only by unconfirmed ALREADY_EXISTS must be rearmed when the same decision is current.');
$legacyFalseSuccessReactivation = array_values(array_filter(
    $legacyFalseSuccessDb->executed,
    static fn(array $execution): bool => str_contains((string)($execution['sql']??''),"SET status='PENDING'")
));
toSame(1,count($legacyFalseSuccessReactivation),'Legacy false success recovery must perform one guarded reactivation.');
toAssert(str_contains((string)($legacyFalseSuccessReactivation[0]['sql']??''),"status='SUCCEEDED'"),'Legacy false success recovery must only rewrite a still-SUCCEEDED row.');

$confirmedSuccessDb = new TenantOutboxMemoryPdo();
$confirmedSuccessOutbox = new SvAmazonTenantReturnsOutbox($confirmedSuccessDb,$context);
$confirmedSuccessKey = $confirmedSuccessOutbox->deterministicKey('SELLER_SUPPORT_UPDATE',77,'confirmed-update');
$confirmedSuccessDb->queue(['fetch'=>['id'=>77]]);
$confirmedSuccessDb->queue(['throw'=>outboxDuplicate()]);
$confirmedSuccessDb->queue(['fetch'=>[
    'id'=>409,'status'=>'SUCCEEDED','attempt_count'=>1,'kind'=>'SELLER_SUPPORT_UPDATE','case_id'=>77,
    'last_error'=>null,
    'payload_json'=>'{"order_id":"702-6823050-9173862","support_case_id":"22426419421"}',
]]);
$confirmedSuccessDb->queue(['fetch'=>[
    'payload_json'=>'{"action":"SELLER_SUPPORT_UPDATE","status":"ALREADY_EXISTS","reason":"SUPPORT_UPDATE_READBACK_CONFIRMED","submitted":false,"external_id":"22426419421"}',
]]);
$confirmedSuccess = $confirmedSuccessOutbox->enqueueResult(
    'SELLER_SUPPORT_UPDATE',77,$legacyFalseSuccessPayload,$confirmedSuccessKey
);
toSame(false,$confirmedSuccess['enqueued']??null,'An update with explicit Seller Central readback proof must remain terminal and must never be rearmed.');
$confirmedSuccessReactivation = array_values(array_filter(
    $confirmedSuccessDb->executed,
    static fn(array $execution): bool => str_contains((string)($execution['sql']??''),"SET status='PENDING'")
));
toSame([],$confirmedSuccessReactivation,'Explicit Seller Central readback proof must prevent duplicate delivery.');

$pendingRow = [
    'id'=>301,'tenant_id'=>1,'amazon_connection_id'=>10,'case_id'=>77,'kind'=>'SAFE_T_SUBMIT',
    'idempotency_key'=>$key,'payload_json'=>'{"order_id":"702-1234567-7654321"}',
    'status'=>'PENDING','attempt_count'=>0,'available_at'=>'2026-09-04 12:00:00',
    'locked_at'=>null,'last_error'=>null,'created_at'=>'2026-09-04 11:59:00','updated_at'=>'2026-09-04 11:59:00',
];
$db->queue(['rows'=>[$pendingRow]]);
$db->queue(['row_count'=>1]);
$claimed = $outbox->claimBatch(10, ['SAFE_T_SUBMIT']);
toSame(1, count($claimed), 'Claim must return the scoped pending row.');
toSame(1, $claimed[0]['attempt_count'] ?? null, 'Claim must increment attempt_count in the returned row.');
toSame('702-1234567-7654321', $claimed[0]['payload']['order_id'] ?? null, 'Claim must decode payload JSON.');
toAssert(!$db->inTransaction(), 'Claim transaction must be closed.');

$db->queue(['fetch'=>$pendingRow]);
$found = $outbox->findOwned(301);
toSame(301, $found['id'] ?? null, 'findOwned must return the scoped row.');
toSame('702-1234567-7654321', $found['payload']['order_id'] ?? null, 'findOwned must decode payload.');

$db->queue(['row_count'=>1]);
$outbox->markSucceeded(301);
$db->queue(['row_count'=>0]);
toThrows(fn()=>$outbox->markSucceeded(999), 'Cross-tenant or missing success update must fail closed.');

$db->queue(['row_count'=>1]);
$outbox->releaseUnprocessed(301);
$db->queue(['row_count'=>1]);
$outbox->reschedule(301, new DateTimeImmutable('2026-09-04 13:00:00', new DateTimeZone('UTC')), 'Authorization: Bearer top-secret-token');
$rescheduleExecution = $db->executed[array_key_last($db->executed)];
toAssert(!str_contains((string)($rescheduleExecution['params'][':last_error'] ?? ''), 'top-secret-token'), 'Persisted errors must redact bearer tokens.');
toAssert(str_contains((string)($rescheduleExecution['params'][':last_error'] ?? ''), '[REDACTED]'), 'Persisted errors must mark redaction.');

$now = new DateTimeImmutable('2026-09-04 12:00:00', new DateTimeZone('UTC'));
$retryRow = array_replace($pendingRow, ['attempt_count'=>1,'status'=>'PROCESSING']);
$db->queue(['row_count'=>1]);
$retry = $outbox->markFailed($retryRow, 'temporary failure', $now);
toSame('RETRY', $retry['status'], 'Transient failure must be rescheduled.');
toSame('2026-09-04 12:01:00', $retry['next_at']?->format('Y-m-d H:i:s'), 'First retry must use 60-second backoff.');

$foreignRow = array_replace($retryRow, ['tenant_id'=>2,'amazon_connection_id'=>20]);
toThrows(fn()=>$outbox->markFailed($foreignRow, 'must not touch'), 'Foreign row must be rejected before SQL.');

$deadRow = array_replace($pendingRow, ['attempt_count'=>5,'status'=>'PROCESSING']);
$db->queue(['row_count'=>1]);
$db->queue(['row_count'=>1]);
$dead = $outbox->markFailed($deadRow, new RuntimeException('permanent failure'), $now);
toSame('DEAD_LETTER', $dead['status'], 'Exhausted action must move to dead letter.');
toAssert(!$db->inTransaction(), 'Dead-letter transaction must be closed.');

$deadline = SvAmazonTenantReturnsOutbox::retryDecision([
    'attempt_count'=>1,
    'payload'=>['deadline_at'=>'2026-09-04T12:00:30Z'],
], $now);
toSame('DEAD_LETTER', $deadline['status'], 'A retry that crosses a deadline must fail terminally.');
toSame(true, SvAmazonTenantReturnsOutbox::leaseExpired('2026-09-04 11:50:00', $now), 'Stale lease must be reclaimable.');
toSame(false, SvAmazonTenantReturnsOutbox::leaseExpired('2026-09-04 11:59:00', $now), 'Fresh lease must remain owned.');
toThrows(fn()=>$outbox->enqueue('', 77, [], $key), 'Empty outbox kind must be rejected.');
toThrows(fn()=>$outbox->enqueue('SAFE_T_SUBMIT', 0, [], $key), 'Invalid case ID must be rejected.');
toThrows(fn()=>$outbox->enqueue('SAFE_T_SUBMIT', 77, [], 'bad-key'), 'Invalid idempotency key must be rejected.');

$secretRejected = false;
try { $outbox->enqueue('SAFE_T_SUBMIT', 77, ['refreshToken'=>'secret'], $key); }
catch (InvalidArgumentException) { $secretRejected = true; }
toAssert($secretRejected, 'Outbox payload must reject secret-bearing keys before database access.');

foreach ($db->executed as $execution) {
    $sql = $execution['sql'];
    if (str_contains($sql, "SET status='SUCCEEDED'") || str_contains($sql, "SET status='DEAD_LETTER'")
        || (str_contains($sql, "SET status='PENDING'") && !str_contains($sql, 'attempt_count=GREATEST'))) {
        toAssert(str_contains($sql, "status='PROCESSING'"), 'Result transitions must require a currently PROCESSING lease.');
    }
    toAssert(str_contains($execution['sql'], 'tenant_id'), 'Every outbox SQL statement must include tenant_id.');
    toAssert(str_contains($execution['sql'], 'amazon_connection_id'), 'Every outbox SQL statement must include amazon_connection_id.');
    toSame(1, $execution['params'][':tenant_id'] ?? null, 'Every outbox SQL execution must bind tenant_id.');
    toSame(10, $execution['params'][':amazon_connection_id'] ?? null, 'Every outbox SQL execution must bind amazon_connection_id.');
}

$rearmDb = new TenantOutboxMemoryPdo();
$rearmOutbox = new SvAmazonTenantReturnsOutbox($rearmDb, $context);
$rearmDb->queue(['row_count'=>2]);
toSame(2, $rearmOutbox->reactivateSafeDeferredExternalWrites(), 'Current live drift fixes must wake their deferred outbox rows immediately after an outbox stack revision.');
$rearmExecution = $rearmDb->executed[array_key_last($rearmDb->executed)] ?? [];
$rearmSql = (string)($rearmExecution['sql'] ?? '');
$rearmParams = $rearmExecution['params'] ?? [];
toAssert(str_contains($rearmSql, "kind='SAFE_T_APPEAL'"), 'SAFE-T appeal recovery must be eligible for controlled reactivation.');
toSame('UI_DRIFT: SAFE_T_APPEAL_SEND_MISSING', $rearmParams[':safe_t_appeal_send_missing'] ?? null, 'SAFE-T appeal Send drift must be explicitly rearmable.');
toSame('UI_DRIFT: SUPPORT_GENERAL_TROUBLESHOOTER_EXHAUSTED', $rearmParams[':support_general_troubleshooter_exhausted'] ?? null, 'Seller Support exhausted troubleshooter drift must be explicitly rearmable.');

$source = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/TenantOutbox.php');
foreach (['FOR UPDATE SKIP LOCKED','tenant_id','amazon_connection_id','MAX_ATTEMPTS','LEASE_SECONDS'] as $needle) {
    toAssert(str_contains($source, $needle), 'Tenant outbox source missing structural guard: ' . $needle);
}
toAssert(!str_contains($source, 'AMAZON_RETURNS_TENANT_ID'), 'Outbox must not resolve tenant from environment.');
toAssert(!str_contains($source, 'AMAZON_RETURNS_CONNECTION_ID'), 'Outbox must not resolve connection from environment.');
$statusBridgeSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
toAssert(str_contains($statusBridgeSource,'supersedeTerminalReadJobs'), 'Status bridge must sweep obsolete terminal read jobs before ensuring new jobs.');

echo "amazon-returns-tenant-outbox-test: OK\n";
