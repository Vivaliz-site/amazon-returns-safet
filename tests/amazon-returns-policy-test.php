<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Enums.php';
require_once __DIR__ . '/../includes/amazon-returns/EventStore.php';
require_once __DIR__ . '/../includes/amazon-returns/Projector.php';
require_once __DIR__ . '/../includes/amazon-returns/PolicyEngine.php';

function policyAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function policyAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<array<string,mixed>> */
function policyVersions(): array
{
    return [
        [
            'id' => 101,
            'policy_key' => 'amazon-br-normal-return-not-received',
            'marketplace_id' => 'A2Q3Y263D00KWC',
            'program' => 'STANDARD',
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'eligibility_days' => 75,
            'basis' => 'SELLER_DEBIT_OR_REFUND_AT',
            'status' => 'ACTIVE',
        ],
        [
            'id' => 201,
            'policy_key' => 'amazon-br-fba-onsite-return-not-received',
            'marketplace_id' => 'A2Q3Y263D00KWC',
            'program' => 'FBA_ONSITE',
            'effective_from' => '2026-04-21',
            'effective_to' => null,
            'eligibility_days' => 75,
            'basis' => 'SELLER_DEBIT_OR_REFUND_AT',
            'status' => 'ACTIVE',
        ],
        [
            'id' => 202,
            'policy_key' => 'amazon-br-dba-return-not-received',
            'marketplace_id' => 'A2Q3Y263D00KWC',
            'program' => 'DELIVERY_BY_AMAZON',
            'effective_from' => '2026-04-21',
            'effective_to' => null,
            'eligibility_days' => 75,
            'basis' => 'SELLER_DEBIT_OR_REFUND_AT',
            'status' => 'ACTIVE',
        ],
    ];
}

/** @return array<string,mixed> */
function applicableCase(array $changes = []): array
{
    return array_replace([
        'marketplace_id' => 'A2Q3Y263D00KWC',
        'program' => 'STANDARD',
        'order_at' => '2026-05-01 08:00:00',
        'refund_initiator' => 'AMAZON_AUTOMATIC',
        'refund_at' => '2026-05-02 12:00:00',
        'seller_debit_at' => '2026-05-03 12:00:00',
        'quantity_ordered' => 1,
        'quantity_refunded' => 1,
        'quantity_received' => 0,
        'physical_status' => 'NOT_RECEIVED',
        'policies' => policyVersions(),
    ], $changes);
}

$d74 = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(),
    new DateTimeImmutable('2026-07-16 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame(false, $d74['eligible'], 'A normal applicable case must not be eligible at D+74.');
policyAssertSame('2026-07-17 12:00:00', $d74['eligibility_at'], 'Eligibility must use seller debit before refund.');
policyAssertSame(101, $d74['policy_version_id'], 'The selected policy version must be returned.');

$d75 = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(),
    new DateTimeImmutable('2026-07-17 09:00:00-03:00')
);
policyAssertSame(true, $d75['eligible'], 'A normal applicable case must be eligible exactly at D+75 UTC.');
policyAssertSame('SAFE_T_ELIGIBLE', $d75['state'], 'Eligible non-return exposure must enter SAFE_T_ELIGIBLE.');
policyAssertSame(true, $d75['can_auto_write'], 'A classified eligible case may pass the policy write gate.');

$refundFallback = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(['seller_debit_at' => null]),
    new DateTimeImmutable('2026-07-16 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame(true, $refundFallback['eligible'], 'Refund time must be the safe fallback when seller debit time is absent.');
policyAssertSame('2026-07-16 12:00:00', $refundFallback['eligibility_at'], 'Refund fallback must retain UTC time.');

foreach (['FBA_ONSITE' => 201, 'DELIVERY_BY_AMAZON' => 202] as $program => $policyId) {
    $d74x = SvAmazonReturnPolicyEngine::evaluate(
        applicableCase(['program' => $program, 'order_at' => '2026-04-21 00:00:00']),
        new DateTimeImmutable('2026-07-17 11:59:59', new DateTimeZone('UTC'))
    );
    policyAssertSame(false, $d74x['eligible'], "{$program} must not be eligible before D+75.");
    policyAssertSame($policyId, $d74x['policy_version_id'], "{$program} must select its D+75 policy version.");

    $d75x = SvAmazonReturnPolicyEngine::evaluate(
        applicableCase(['program' => $program, 'order_at' => '2026-04-21 00:00:00']),
        new DateTimeImmutable('2026-07-17 12:00:00', new DateTimeZone('UTC'))
    );
    policyAssertSame(true, $d75x['eligible'], "{$program} must be eligible at D+75.");
}

$beforeException = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(['program' => 'FBA_ONSITE', 'order_at' => '2026-04-20 23:59:59']),
    new DateTimeImmutable('2026-07-17 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame(true, $beforeException['eligible'], 'Before 2026-04-21, the normal D+75 policy must apply.');
policyAssertSame(101, $beforeException['policy_version_id'], 'Pre-effective exception must fall back to normal policy.');

$unknownInitiator = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(['refund_initiator' => 'UNKNOWN']),
    new DateTimeImmutable('2026-07-30 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame('POLICY_REVIEW_REQUIRED', $unknownInitiator['state'], 'Unknown initiator requires policy review.');
policyAssertSame(false, $unknownInitiator['can_auto_write'], 'Unknown initiator must never auto-write.');
policyAssertSame(false, $unknownInitiator['auto_write_allowed'], 'Unknown initiator must expose a denied write gate.');

$unresolvedPolicy = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(['marketplace_id' => 'UNKNOWN-MARKETPLACE']),
    new DateTimeImmutable('2026-07-30 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame('POLICY_REVIEW_REQUIRED', $unresolvedPolicy['state'], 'An unresolved policy requires review.');
policyAssertSame(null, $unresolvedPolicy['policy_version_id'], 'An unresolved policy must not fabricate a version.');
policyAssertSame(false, $unresolvedPolicy['can_auto_write'], 'An unresolved policy must never auto-write.');

$received = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(['quantity_received' => 1, 'physical_status' => 'RECEIVED_OK']),
    new DateTimeImmutable('2026-07-30 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame(false, $received['eligible'], 'Physical receipt must stop the non-return path.');
policyAssertSame('RECEIVED_OK', $received['state'], 'Fully received quantity must close as received.');
policyAssertSame(0, $received['exposed_quantity'], 'No quantity remains exposed after full receipt.');

$partial = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(['quantity_ordered' => 3, 'quantity_refunded' => 2, 'quantity_received' => 1]),
    new DateTimeImmutable('2026-07-30 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame(1, $partial['exposed_quantity'], 'Only refunded but unreceived quantity remains exposed.');
policyAssertSame(true, $partial['eligible'], 'An unresolved partial quantity remains eligible.');

$carrierOnly = SvAmazonReturnPolicyEngine::evaluate(
    applicableCase(['physical_status' => 'CARRIER_DELIVERED_PENDING_PHYSICAL']),
    new DateTimeImmutable('2026-07-30 12:00:00', new DateTimeZone('UTC'))
);
policyAssertSame(true, $carrierOnly['eligible'], 'Carrier delivery without warehouse intake must not close the case.');
policyAssertSame(1, $carrierOnly['exposed_quantity'], 'Carrier delivery alone leaves physical exposure open.');

final class AmazonPolicyProjectionPdo extends PDO
{
    /** @var array<string,mixed> */
    public array $case;
    /** @var list<array<string,mixed>> */
    public array $events;
    /** @var list<string> */
    public array $writes = [];

    public function __construct(array $case, array $events)
    {
        $this->case = $case;
        $this->events = $events;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AmazonPolicyProjectionStatement($this, $query);
    }
}

final class AmazonPolicyProjectionStatement extends PDOStatement
{
    /** @var list<array<string,mixed>> */
    private array $result = [];
    private int $cursor = 0;

    public function __construct(private AmazonPolicyProjectionPdo $db, private string $query)
    {
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $sql = strtoupper(ltrim($this->query));
        if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM `AMAZON_RETURN_CASES`')) {
            $this->result = [$this->db->case];
            return true;
        }
        if (str_starts_with($sql, 'SELECT') && str_contains($sql, 'FROM `AMAZON_RETURN_EVENTS`')) {
            $this->result = $this->db->events;
            return true;
        }
        if (str_starts_with($sql, 'UPDATE `AMAZON_RETURN_CASES`')) {
            $this->db->writes[] = $this->query;
            foreach ($params as $key => $value) {
                $column = ltrim((string) $key, ':');
                if ($column !== 'case_id') {
                    $this->db->case[$column] = $value;
                }
            }
            return true;
        }
        if (str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE') || str_starts_with($sql, 'INSERT')) {
            throw new LogicException('Projector attempted a write outside amazon_return_cases: ' . $this->query);
        }
        throw new LogicException('Unexpected projector SQL: ' . $this->query);
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

$projectionDb = new AmazonPolicyProjectionPdo(
    [
        'id' => 77,
        'amazon_order_id' => 'ORDER-1',
        'amazon_order_item_id' => 'ITEM-1',
        'marketplace_id' => 'A2Q3Y263D00KWC',
        'quantity_ordered' => 3,
    ],
    [
        [
            'id' => 1,
            'case_id' => 77,
            'event_type' => 'REFUND_DETECTED',
            'source' => 'SP_API_FINANCES',
            'source_event_id' => 'refund-1',
            'idempotency_key' => hash('sha256', 'refund-1'),
            'occurred_at' => '2026-05-03 12:00:00',
            'payload_json' => json_encode([
                'quantity_refunded' => 2,
                'refund_initiator' => 'AMAZON_AUTOMATIC',
                'program' => 'STANDARD',
                'seller_debit_at' => '2026-05-03 12:00:00',
            ], JSON_THROW_ON_ERROR),
            'evidence_sha256' => null,
            'created_at' => '2026-05-03 12:01:00',
        ],
        [
            'id' => 2,
            'case_id' => 77,
            'event_type' => 'CARRIER_DELIVERED',
            'source' => 'EXTERNAL_CARRIER',
            'source_event_id' => 'tracking-1',
            'idempotency_key' => hash('sha256', 'tracking-1'),
            'occurred_at' => '2026-05-10 12:00:00',
            'payload_json' => '{}',
            'evidence_sha256' => null,
            'created_at' => '2026-05-10 12:01:00',
        ],
        [
            'id' => 3,
            'case_id' => 77,
            'event_type' => 'PHYSICAL_RECEIVED',
            'source' => 'WAREHOUSE',
            'source_event_id' => 'intake-1',
            'idempotency_key' => hash('sha256', 'intake-1'),
            'occurred_at' => '2026-05-11 12:00:00',
            'payload_json' => '{"quantity":1}',
            'evidence_sha256' => null,
            'created_at' => '2026-05-11 12:01:00',
        ],
    ]
);
$projected = SvAmazonReturnProjector::project($projectionDb, 77);
policyAssertSame(2, $projected['quantity_refunded'], 'Projector must derive refunded quantity from events.');
policyAssertSame(1, $projected['quantity_received'], 'Projector must derive physical intake quantity from events.');
policyAssertSame(1, $projected['exposed_quantity'], 'Projection must retain only unresolved quantity exposure.');
policyAssertSame('RECEIVED_DISCREPANT', $projected['physical_status'], 'Partial physical intake must remain discrepant.');
policyAssertSame(1, count($projectionDb->writes), 'Projection must perform exactly one projection-table write.');
policyAssertTrue(
    str_contains(strtoupper($projectionDb->writes[0]), 'UPDATE `AMAZON_RETURN_CASES`'),
    'Projector may write only amazon_return_cases.'
);
$projectedAgain = SvAmazonReturnProjector::project($projectionDb, 77);
policyAssertSame($projected, $projectedAgain, 'Replaying the same append-only timeline must produce identical facts.');
policyAssertSame(2, count($projectionDb->writes), 'Each deterministic replay must make one projection-table write.');

$receivedOkEvents = $projectionDb->events;
$receivedOkEvents[0]['payload_json'] = json_encode([
    'quantity_refunded' => 1,
    'refund_initiator' => 'AMAZON_AUTOMATIC',
    'program' => 'STANDARD',
    'seller_debit_at' => '2026-05-03 12:00:00',
], JSON_THROW_ON_ERROR);
$receivedOkEvents[2]['event_type'] = 'RECEIVED_OK';
$receivedOkEvents[2]['payload_json'] = '{}';
$receivedOkDb = new AmazonPolicyProjectionPdo(
    array_replace($projectionDb->case, ['id' => 78, 'quantity_ordered' => 1]),
    array_map(static function (array $event): array {
        $event['case_id'] = 78;
        return $event;
    }, $receivedOkEvents)
);
$receivedOkProjection = SvAmazonReturnProjector::project($receivedOkDb, 78);
policyAssertSame('RECEIVED_OK', $receivedOkProjection['state'], 'Explicit physical RECEIVED_OK must stop non-return handling.');
policyAssertSame(0, $receivedOkProjection['exposed_quantity'], 'Explicit RECEIVED_OK must resolve the refunded quantity.');

$damagedEvents = $receivedOkDb->events;
$damagedEvents[2]['event_type'] = 'PHYSICAL_RECEIVED';
$damagedEvents[2]['payload_json'] = json_encode(['quantity'=>1,'condition'=>'DAMAGED'], JSON_THROW_ON_ERROR);
$damagedDb = new AmazonPolicyProjectionPdo(
    array_replace($receivedOkDb->case, ['id' => 79, 'quantity_ordered' => 1]),
    array_map(static function (array $event): array { $event['case_id'] = 79; return $event; }, $damagedEvents)
);
$damagedProjection = SvAmazonReturnProjector::project($damagedDb, 79);
policyAssertSame('RECEIVED_DISCREPANT', $damagedProjection['state'], 'Full quantity returned damaged must not close as RECEIVED_OK.');
policyAssertSame('RECEIVED_DISCREPANT', $damagedProjection['physical_status'], 'Damaged physical intake must remain a discrepancy path.');

$wrongItemEvents = $receivedOkDb->events;
$wrongItemEvents[2]['event_type'] = 'PHYSICAL_RECEIVED';
$wrongItemEvents[2]['payload_json'] = json_encode(['quantity'=>0,'condition'=>'WRONG_ITEM'], JSON_THROW_ON_ERROR);
$wrongItemDb = new AmazonPolicyProjectionPdo(
    array_replace($receivedOkDb->case, ['id' => 80, 'quantity_ordered' => 1]),
    array_map(static function (array $event): array { $event['case_id'] = 80; return $event; }, $wrongItemEvents)
);
$wrongItemProjection = SvAmazonReturnProjector::project($wrongItemDb, 80);
policyAssertSame('RECEIVED_DISCREPANT', $wrongItemProjection['state'], 'Wrong item/package evidence must create discrepancy even when correct quantity received is zero.');
policyAssertSame(1, $wrongItemProjection['exposed_quantity'], 'Wrong item must leave expected refunded unit unresolved.');


$advancedDb = new AmazonPolicyProjectionPdo(
    [
        'id'=>79,'amazon_order_id'=>'ORDER-ADV','amazon_order_item_id'=>'ITEM-ADV','marketplace_id'=>'A2Q3Y263D00KWC',
        'quantity_ordered'=>1,'state'=>'SAFE_T_DENIED','physical_status'=>'NOT_RECEIVED','terminal_reason'=>null,'closed_at'=>null,
    ],
    [[
        'id'=>1,'case_id'=>79,'event_type'=>'REFUND_CONFIRMED','source'=>'SP_API_FINANCES','source_event_id'=>'txn-adv',
        'idempotency_key'=>hash('sha256','txn-adv'),'occurred_at'=>'2026-07-01 12:00:00',
        'payload_json'=>json_encode(['quantity_refunded'=>1,'refund_initiator'=>'AMAZON_AUTOMATIC','program'=>'STANDARD','seller_debit_at'=>'2026-07-01 12:00:00'], JSON_THROW_ON_ERROR),
        'evidence_sha256'=>null,'created_at'=>'2026-07-01 12:00:01',
    ]]
);
$advancedProjection = SvAmazonReturnProjector::project($advancedDb,79);
policyAssertSame('SAFE_T_DENIED',$advancedProjection['state'],'Projection must not downgrade advanced SAFE-T state during replay.');

$reviewDb = new AmazonPolicyProjectionPdo(
    [
        'id'=>80,'amazon_order_id'=>'ORDER-REVIEW','amazon_order_item_id'=>'ITEM-REVIEW','marketplace_id'=>'A2Q3Y263D00KWC',
        'quantity_ordered'=>1,'state'=>'POLICY_REVIEW_REQUIRED','physical_status'=>'NOT_RECEIVED','terminal_reason'=>null,'closed_at'=>null,
    ],
    []
);
$reviewProjection = SvAmazonReturnProjector::project($reviewDb,80);
policyAssertSame('POLICY_REVIEW_REQUIRED',$reviewProjection['state'],'Projection must preserve an explicit safety review gate.');

echo "amazon-returns-policy-test: OK\n";
