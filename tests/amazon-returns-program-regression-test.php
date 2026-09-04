<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SpApiEventSink.php';

function prgSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

/**
 * Minimal fake covering only the amazon_return_cases upsert path exercised by
 * SvAmazonSpApiEventSink::upsertCase(), invoked directly via reflection since
 * it is private. Kept intentionally narrow (no events/transactions tables)
 * to isolate the program-classification regression from the rest of persist().
 */
final class ProgramRegressionMemoryPdo extends PDO {
    /** @var array<int,array{amazon_order_id:string,amazon_order_item_id:string,program:string}>> */
    public array $cases = [];
    public int $nextId = 1;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ProgramRegressionMemoryStatement($this, $query);
    }
}
final class ProgramRegressionMemoryStatement extends PDOStatement {
    private array $result = [];
    public function __construct(private ProgramRegressionMemoryPdo $db, private string $query) {}
    public function execute(?array $params = null): bool {
        $params ??= [];
        $sql = strtoupper($this->query);
        if (str_starts_with(ltrim($sql), 'INSERT INTO AMAZON_RETURN_CASES')) {
            $existingId = null;
            foreach ($this->db->cases as $id => $case) {
                if ($case['amazon_order_id'] === $params[':order_id'] && $case['amazon_order_item_id'] === $params[':item_id']) {
                    $existingId = $id;
                    break;
                }
            }
            if ($existingId !== null) {
                // Mirrors the real ON DUPLICATE KEY UPDATE column list, including
                // the IF(VALUES(program)<>'UNKNOWN',VALUES(program),program) guard.
                if ($params[':program'] !== 'UNKNOWN') {
                    $this->db->cases[$existingId]['program'] = $params[':program'];
                }
            } else {
                $this->db->cases[$this->db->nextId] = [
                    'amazon_order_id' => $params[':order_id'],
                    'amazon_order_item_id' => $params[':item_id'],
                    'program' => $params[':program'],
                ];
                $this->db->nextId++;
            }
            return true;
        }
        if (str_starts_with(ltrim($sql), 'UPDATE AMAZON_RETURN_CASES SET AMAZON_ORDER_ITEM_ID')) {
            // resolvePlaceholder(): no placeholder rows in this fixture, always a no-op match.
            return true;
        }
        if (str_starts_with(ltrim($sql), 'SELECT ID FROM AMAZON_RETURN_CASES')) {
            $this->result = [];
            foreach ($this->db->cases as $id => $case) {
                if ($case['amazon_order_id'] === $params[':order_id'] && $case['amazon_order_item_id'] === $params[':item_id']) {
                    $this->result[] = $id;
                    break;
                }
            }
            return true;
        }
        throw new LogicException('Unexpected SQL in program-regression test: ' . $this->query);
    }
    public function fetchColumn(int $column = 0): mixed {
        return $this->result === [] ? false : $this->result[0];
    }
}

$upsertCase = new ReflectionMethod(SvAmazonSpApiEventSink::class, 'upsertCase');
$upsertCase->setAccessible(true);

$db = new ProgramRegressionMemoryPdo();

// First sync: Orders API returns an explicit DBA program marker -> case is classified.
$orderWithProgram = ['order_id' => '702-1111111-1111111', 'programs' => ['DELIVERY_BY_AMAZON'], 'fulfillment' => []];
$item = ['orderItemId' => 'item-1', 'quantityOrdered' => 1];
$firstId = $upsertCase->invoke(null, $db, $orderWithProgram, $item, true);
prgSame('DELIVERY_BY_AMAZON', $db->cases[$firstId]['program'], 'First sync with an explicit program marker must classify the case.');

// Second sync (e.g. a later 30-minute reconciliation cycle): Amazon's Orders API
// response for this same order no longer includes the programs/fulfillment data
// (a real, observed occurrence for older orders) -- programFromOrder() falls
// back to UNKNOWN. A previously-known classification must never be silently
// downgraded back to UNKNOWN by a routine resync; only a real signal may
// change it.
$orderWithoutProgram = ['order_id' => '702-1111111-1111111', 'programs' => [], 'fulfillment' => []];
$secondId = $upsertCase->invoke(null, $db, $orderWithoutProgram, $item, true);
prgSame($firstId, $secondId, 'Resync must resolve to the same case.');
prgSame('DELIVERY_BY_AMAZON', $db->cases[$secondId]['program'], 'A routine resync without program evidence must not clobber a previously known classification.');

// A resync that DOES carry real, different evidence may still update the case
// (e.g. corrected data, or a legitimate reclassification from UNKNOWN).
$db2 = new ProgramRegressionMemoryPdo();
$unknownOrder = ['order_id' => '702-2222222-2222222', 'programs' => [], 'fulfillment' => []];
$unclassifiedId = $upsertCase->invoke(null, $db2, $unknownOrder, $item, true);
prgSame('UNKNOWN', $db2->cases[$unclassifiedId]['program'], 'A first sync with no program evidence stays UNKNOWN (never guessed).');
$laterClassifiedOrder = ['order_id' => '702-2222222-2222222', 'programs' => [], 'fulfillment' => ['fulfilledBy' => 'MERCHANT']];
$upsertCase->invoke(null, $db2, $laterClassifiedOrder, $item, true);
prgSame('STANDARD', $db2->cases[$unclassifiedId]['program'], 'A later resync with real evidence must still be able to classify a previously UNKNOWN case.');

echo "amazon-returns-program-regression-test: OK\n";
