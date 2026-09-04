<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Enums.php';
require_once __DIR__ . '/../includes/amazon-returns/Schema.php';
require_once __DIR__ . '/../includes/amazon-returns/EventStore.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true)
        );
    }
}

final class AmazonReturnsMemoryPdo extends PDO
{
    /** @var array<int,array<string,mixed>> */
    public array $events = [];
    public int $nextId = 1;
    public int $insertAttempts = 0;
    public int $updateAttempts = 0;
    /** @var list<string> */
    public array $ddlExecutions = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AmazonReturnsMemoryStatement($this, $query);
    }

    public function exec(string $statement): int|false
    {
        $this->ddlExecutions[] = $statement;
        return 0;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) ($this->nextId - 1);
    }
}

final class AmazonReturnsMemoryStatement extends PDOStatement
{
    /** @var array<int,array<string,mixed>> */
    private array $result = [];
    private int $cursor = 0;

    public function __construct(
        private AmazonReturnsMemoryPdo $db,
        private string $query
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $normalized = strtoupper(ltrim($this->query));

        if (str_starts_with($normalized, 'INSERT INTO')) {
            $this->db->insertAttempts++;
            foreach ($this->db->events as $row) {
                if ($row['idempotency_key'] === $params[':idempotency_key']) {
                    throw new PDOException('Duplicate idempotency key', 23000);
                }
            }

            $id = $this->db->nextId++;
            $this->db->events[$id] = [
                'id' => $id,
                'case_id' => $params[':case_id'],
                'event_type' => $params[':event_type'],
                'source' => $params[':source'],
                'source_event_id' => $params[':source_event_id'],
                'idempotency_key' => $params[':idempotency_key'],
                'occurred_at' => $params[':occurred_at'],
                'payload_json' => $params[':payload_json'],
                'evidence_sha256' => $params[':evidence_sha256'],
                'created_at' => $params[':created_at'],
            ];
            return true;
        }

        if (str_starts_with($normalized, 'UPDATE ') || str_starts_with($normalized, 'DELETE ')) {
            $this->db->updateAttempts++;
            throw new LogicException('The event store must be append-only.');
        }

        if (str_contains($normalized, 'WHERE `IDEMPOTENCY_KEY` =')) {
            $this->result = [];
            foreach ($this->db->events as $row) {
                if ($row['idempotency_key'] === $params[':idempotency_key']) {
                    $this->result[] = ['id' => $row['id']];
                }
            }
            return true;
        }

        if (str_contains($normalized, 'WHERE `CASE_ID` =')) {
            $this->result = array_values(array_filter(
                $this->db->events,
                static fn(array $row): bool => $row['case_id'] === $params[':case_id']
            ));
            usort(
                $this->result,
                static fn(array $left, array $right): int => [$left['occurred_at'], $left['id']]
                    <=> [$right['occurred_at'], $right['id']]
            );
            return true;
        }

        throw new LogicException('Unexpected SQL in test seam: ' . $this->query);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result[$this->cursor++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->result;
    }
}

$expectedStates = [
    'REFUND_DETECTED',
    'AWAITING_RETURN',
    'IN_TRANSIT',
    'CARRIER_DELIVERED_PENDING_PHYSICAL',
    'RECEIVED_OK',
    'RECEIVED_DISCREPANT',
    'SAFE_T_ELIGIBLE',
    'SAFE_T_READY',
    'SAFE_T_SUBMITTED',
    'SAFE_T_APPROVED',
    'SAFE_T_DENIED',
    'SAFE_T_INFO_REQUESTED',
    'APPEAL_REQUIRED',
    'APPEAL_SUBMITTED',
    'APPEAL_APPROVED',
    'APPEAL_DENIED_FINAL',
    'EMAIL_REVIEW_SENT',
    'CREDIT_PENDING',
    'RECOVERED',
    'SUPPORT_ESCALATION',
    'CLOSED_LOSS',
    'POLICY_REVIEW_REQUIRED',
];
assertSameValue($expectedStates, SvAmazonReturnStates::all(), 'Projection states must match the approved spec.');
assertSameValue(
    ['RECEIVED_OK', 'RECOVERED', 'CLOSED_LOSS'],
    SvAmazonReturnStates::terminal(),
    'Only the specified states are terminal.'
);
assertTrue(SvAmazonReturnStates::isValid('SAFE_T_READY'), 'A declared state must validate.');
assertTrue(!SvAmazonReturnStates::isValid('MADE_UP'), 'An undeclared state must not validate.');
assertTrue(SvAmazonReturnStates::isTerminal('RECOVERED'), 'RECOVERED must be terminal.');
assertTrue(!SvAmazonReturnStates::isTerminal('SAFE_T_APPROVED'), 'Approval is not terminal before credit.');
assertSameValue(
    ['AMAZON_AUTOMATIC', 'AMAZON_CUSTOMER_SERVICE', 'SELLER', 'A_TO_Z', 'UNKNOWN'],
    SvAmazonRefundInitiators::all(),
    'Refund initiators must match the approved spec.'
);

$ddl = implode("\n", SvAmazonReturnsSchema::statements());
$tables = [
    'amazon_return_cases',
    'amazon_return_events',
    'amazon_return_policies',
    'amazon_return_evidence',
    'amazon_return_outbox',
    'amazon_return_dead_letters',
    'amazon_return_source_cursors',
    'amazon_return_overrides',
];
foreach ($tables as $table) {
    assertTrue(str_contains($ddl, "CREATE TABLE IF NOT EXISTS `{$table}`"), "DDL must define {$table}.");
}
$requiredColumns = [
    'amazon_return_cases' => [
        'id', 'amazon_order_id', 'amazon_order_item_id', 'marketplace_id', 'sku', 'asin',
        'quantity_ordered', 'quantity_refunded', 'quantity_received', 'program', 'refund_initiator',
        'refund_at', 'seller_debit_at', 'refund_amount', 'expected_reimbursement_amount',
        'reconciled_credit_amount', 'physical_status', 'state', 'policy_version_id', 'eligibility_at',
        'next_action_at', 'safe_t_id', 'support_case_id', 'repeated_denial_count',
        'last_denial_fingerprint', 'appeal_deadline_at', 'terminal_reason', 'closed_at', 'created_at', 'updated_at',
    ],
    'amazon_return_events' => [
        'id', 'case_id', 'event_type', 'source', 'source_event_id', 'idempotency_key', 'occurred_at',
        'payload_json', 'evidence_sha256', 'created_at',
    ],
    'amazon_return_policies' => [
        'id', 'policy_key', 'marketplace_id', 'program', 'effective_from', 'effective_to',
        'eligibility_days', 'basis', 'source_url', 'source_hash', 'status', 'created_at',
    ],
    'amazon_return_evidence' => [
        'id', 'case_id', 'kind', 'source', 'external_id', 'content_sha256', 'storage_ref',
        'metadata_json', 'captured_at', 'created_at',
    ],
    'amazon_return_outbox' => [
        'id', 'case_id', 'kind', 'idempotency_key', 'payload_json', 'status', 'attempt_count',
        'available_at', 'locked_at', 'last_error', 'created_at', 'updated_at',
    ],
    'amazon_return_dead_letters' => [
        'id', 'outbox_id', 'case_id', 'kind', 'idempotency_key', 'payload_sha256', 'payload_json',
        'error_class', 'error_message', 'attempt_count', 'first_attempt_at', 'failed_at', 'created_at',
    ],
    'amazon_return_source_cursors' => [
        'id', 'source', 'cursor_key', 'cursor_value', 'metadata_json', 'observed_at', 'created_at', 'updated_at',
    ],
    'amazon_return_overrides' => [
        'id', 'case_id', 'actor_id', 'reason', 'before_json', 'after_json', 'created_at',
    ],
];
foreach (SvAmazonReturnsSchema::statements() as $statement) {
    foreach ($requiredColumns as $table => $columns) {
        if (!str_contains($statement, "`{$table}`")) {
            continue;
        }
        foreach ($columns as $column) {
            assertTrue(str_contains($statement, "`{$column}`"), "DDL for {$table} is missing {$column}.");
        }
    }
}
foreach ([
    'UNIQUE KEY `uq_amazon_return_case_order_item` (`amazon_order_id`, `amazon_order_item_id`)',
    'KEY `idx_amazon_return_cases_state_action` (`state`, `next_action_at`)',
    'KEY `idx_amazon_return_cases_safe_t` (`safe_t_id`)',
    'KEY `idx_amazon_return_cases_support_case` (`support_case_id`)',
    'KEY `idx_amazon_return_cases_eligibility` (`eligibility_at`)',
    'KEY `idx_amazon_return_cases_seller_debit` (`seller_debit_at`)',
    '`idempotency_key` CHAR(64) NOT NULL',
    'UNIQUE KEY `uq_amazon_return_events_idempotency` (`idempotency_key`)',
    'KEY `idx_amazon_return_events_case_time` (`case_id`, `occurred_at`, `id`)',
    'UNIQUE KEY `uq_amazon_return_policy_version` (`policy_key`, `marketplace_id`, `program`, `effective_from`)',
    'UNIQUE KEY `uq_amazon_return_evidence_content` (`case_id`, `kind`, `content_sha256`)',
    'UNIQUE KEY `uq_amazon_return_outbox_idempotency` (`idempotency_key`)',
    'KEY `idx_amazon_return_outbox_available` (`status`, `available_at`)',
    'KEY `idx_amazon_return_outbox_case_kind` (`case_id`, `kind`)',
] as $requiredSql) {
    assertTrue(str_contains($ddl, $requiredSql), "DDL is missing required definition: {$requiredSql}");
}
assertSameValue(8, count(SvAmazonReturnsSchema::statements()), 'Schema generation must be deterministic: one statement per table.');
assertTrue(substr_count($ddl, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === 8, 'Every table must use InnoDB/utf8mb4.');
$schemaDb = new AmazonReturnsMemoryPdo();
SvAmazonReturnsSchema::ensure($schemaDb);
SvAmazonReturnsSchema::ensure($schemaDb);
assertSameValue(16, count($schemaDb->ddlExecutions), 'Calling ensure twice must safely replay all CREATE TABLE IF NOT EXISTS statements.');
assertSameValue(
    $schemaDb->ddlExecutions[0],
    $schemaDb->ddlExecutions[8],
    'Repeated schema generation must be deterministic.'
);

$db = new AmazonReturnsMemoryPdo();
$baseEvent = [
    'case_id' => 42,
    'event_type' => 'REFUND_DETECTED',
    'source' => 'SP_API_FINANCES',
    'source_event_id' => 'txn-123',
    'idempotency_key' => hash('sha256', 'finances|txn-123|order-item-9'),
    'occurred_at' => '2026-09-01 10:00:00',
    'payload' => ['amount' => '49.90', 'currency' => 'BRL'],
    'evidence_sha256' => hash('sha256', 'sanitized evidence'),
];
$firstId = SvAmazonReturnEventStore::append($db, $baseEvent);
$duplicateId = SvAmazonReturnEventStore::append($db, array_replace($baseEvent, ['payload' => ['amount' => '999.99']]));
assertSameValue(1, $firstId, 'First append must return the inserted event ID.');
assertSameValue($firstId, $duplicateId, 'Duplicate append must resolve to the existing logical event.');
assertSameValue(1, count($db->events), 'Duplicate idempotency key must not create a second event.');
assertSameValue(2, $db->insertAttempts, 'Duplicate behavior must be exercised at the unique insert boundary.');
assertSameValue(0, $db->updateAttempts, 'Event store must never update or delete an event.');

$secondEvent = $baseEvent;
$secondEvent['event_type'] = 'CARRIER_DELIVERED';
$secondEvent['source'] = 'EXTERNAL_CARRIER';
$secondEvent['source_event_id'] = 'tracking-7';
$secondEvent['idempotency_key'] = hash('sha256', 'carrier|tracking-7|delivered');
$secondEvent['occurred_at'] = '2026-09-01 09:00:00';
SvAmazonReturnEventStore::append($db, $secondEvent);

$events = SvAmazonReturnEventStore::eventsForCase($db, 42);
assertSameValue(2, count($events), 'Case timeline must return all events for the case.');
assertSameValue('CARRIER_DELIVERED', $events[0]['event_type'], 'Case events must be ordered by occurrence then ID.');
assertSameValue(['amount' => '49.90', 'currency' => 'BRL'], $events[1]['payload'], 'Stored JSON must be decoded.');

assertSameValue(
    hash('sha256', 'finances|txn-123|order-item-9'),
    SvAmazonReturnEventStore::deterministicKey('finances', 'txn-123', 'order-item-9'),
    'Idempotency key helper must be deterministic.'
);

echo "amazon-returns-domain-test: OK\n";
