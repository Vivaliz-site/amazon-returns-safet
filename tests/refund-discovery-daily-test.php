<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/RefundDiscovery.php';

function refundDiscoveryAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$now = new DateTimeImmutable('2026-11-05T18:00:00Z');
$a = '123-1234567-1234567';
$b = '456-1234567-1234567';
$transport = new class($a, $b) {
    public array $calls = [];
    public function __construct(private string $a, private string $b) {}
    public function marketplaceId(): string { return 'AMAZON_BR_TEST'; }
    public function request(string $method, string $path, array $query): array
    {
        refundDiscoveryAssert($method === 'GET', 'Discovery must be read-only.');
        refundDiscoveryAssert($path === '/finances/2024-06-19/transactions', 'Unexpected endpoint.');
        $this->calls[] = $query;
        $order = static fn(string $id): array => [[
            'relatedIdentifierName' => 'ORDER_ID', 'relatedIdentifierValue' => $id,
        ]];
        if (isset($query['nextToken'])) {
            return ['status' => 200, 'data' => ['payload' => [
                'transactions' => [
                    ['transactionType' => 'Refund', 'transactionStatus' => 'DEFERRED_RELEASED',
                     'relatedIdentifiers' => $order($this->b)],
                    ['transactionType' => 'Refund', 'transactionStatus' => 'RELEASED',
                     'relatedIdentifiers' => $order($this->a)],
                ],
                'nextToken' => null,
            ]]];
        }
        return ['status' => 200, 'data' => ['payload' => [
            'transactions' => [
                ['transactionType' => 'Refund', 'transactionStatus' => 'RELEASED',
                 'relatedIdentifiers' => $order($this->a)],
                ['transactionType' => 'Refund', 'transactionStatus' => 'PENDING',
                 'relatedIdentifiers' => $order('789-1234567-1234567')],
                ['transactionType' => 'Charge', 'transactionStatus' => 'RELEASED',
                 'relatedIdentifiers' => $order('789-1234567-1234567')],
            ],
            'nextToken' => 'PAGE2',
        ]]];
    }
};

$result = SvAmazonRefundDiscovery::scan($transport, 1, $now, static fn(): null => null);
refundDiscoveryAssert($result['orders'] === [$a, $b], 'Released refund orders must be unique and sorted.');
refundDiscoveryAssert($result['pages'] === 2 && $result['windows'] === 1,
    'Refund discovery must follow Finances pagination.');
refundDiscoveryAssert($result['refunded_rows'] === 3, 'Pending/non-refund transactions must be excluded.');
refundDiscoveryAssert($transport->calls[0]['postedBefore'] === '2026-11-05T17:57:00Z',
    'Finances must have a three-minute UTC margin.');
refundDiscoveryAssert($transport->calls[1]['nextToken'] === 'PAGE2',
    'Cursor must be passed exactly to subsequent calls.');

$repeat = new class {
    public function marketplaceId(): string { return 'TEST_BR'; }
    public function request(string $method, string $path, array $query): array
    {
        return ['status' => 200, 'data' => ['transactions' => [], 'nextToken' => 'LOOP']];
    }
};
try {
    SvAmazonRefundDiscovery::scan($repeat, 1, $now, static fn(): null => null);
    throw new RuntimeException('Repeated cursors must fail closed.');
} catch (UnexpectedValueException $expected) {
    // Expected.
}
$httpFailure = new class {
    public function marketplaceId(): string { return 'TEST_BR'; }
    public function request(string $method, string $path, array $query): array
    {
        return ['status' => 400, 'data' => ['errors' => [['code' => 'InvalidInput']]]];
    }
};
try {
    SvAmazonRefundDiscovery::scan($httpFailure, 1, $now, static fn(): null => null);
    throw new RuntimeException('Finances failures must not become successful empty scans.');
} catch (RuntimeException $expected) {
    refundDiscoveryAssert(str_contains($expected->getMessage(), 'Finances refund discovery'),
        'Expected the Finances HTTP failure.');
}
foreach ([0, 91] as $days) {
    try {
        SvAmazonRefundDiscovery::scan($transport, $days, $now, static fn(): null => null);
        throw new RuntimeException('Out-of-window scan accepted.');
    } catch (InvalidArgumentException $expected) {
        // Expected.
    }
}
refundDiscoveryAssert(SvAmazonRefundDiscovery::delayForOrderMicros(0) === 0,
    'First refund read must not incur unnecessary pacing.');
refundDiscoveryAssert(SvAmazonRefundDiscovery::delayForOrderMicros(1) === 2200000
    && SvAmazonRefundDiscovery::delayForOrderMicros(20) === 2200000,
    'Subsequent order reads must respect the 2.2-second throttle.');
try {
    SvAmazonRefundDiscovery::delayForOrderMicros(-1);
    throw new RuntimeException('Negative order index must fail.');
} catch (InvalidArgumentException $expected) {
    // Expected.
}
echo "refund-discovery-daily-test: OK\n";
