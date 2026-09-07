<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/SpApiEventSink.php';

function refundIdentitySame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

$observation = SvAmazonSpApiEventSink::refundObservation([
    [
        'transaction_id' => 'refund-deferred-26670',
        'transaction_type' => 'Refund',
        'transaction_status' => 'DEFERRED_RELEASED',
        'posted_at' => '2026-06-21T21:20:33Z',
        'total_amount' => ['amount' => '-266.70', 'currency' => 'BRL'],
        'related_identifiers' => [
            ['name' => 'ORDER_ID', 'value' => '702-5349464-0245862'],
            ['name' => 'REFUND_ID', 'value' => 'refund-economic-1'],
            ['name' => 'RELEASE_TRANSACTION_ID', 'value' => 'refund-released-26670'],
            ['name' => 'FINANCIAL_EVENT_GROUP_ID', 'value' => 'group-deferred'],
        ],
    ],
    [
        'transaction_id' => 'refund-released-26670',
        'transaction_type' => 'Refund',
        'transaction_status' => 'RELEASED',
        'posted_at' => '2026-06-27T18:59:09Z',
        'total_amount' => ['amount' => '-266.70', 'currency' => 'BRL'],
        'related_identifiers' => [
            ['name' => 'ORDER_ID', 'value' => '702-5349464-0245862'],
            ['name' => 'REFUND_ID', 'value' => 'refund-economic-1'],
            ['name' => 'DEFERRED_TRANSACTION_ID', 'value' => 'refund-deferred-26670'],
            ['name' => 'FINANCIAL_EVENT_GROUP_ID', 'value' => 'group-released'],
        ],
    ],
]);

refundIdentitySame('266.70', $observation['refund_amount'] ?? null, 'The same Amazon REFUND_ID must count once across deferred/released lifecycle representations.');
refundIdentitySame('2026-06-21 21:20:33', $observation['seller_debit_at'] ?? null, 'Refund lifecycle dedupe must preserve the earliest seller debit timestamp.');
refundIdentitySame(
    ['refund-deferred-26670', 'refund-released-26670'],
    $observation['transaction_ids'] ?? null,
    'Both transaction IDs must remain attached as audit evidence.'
);

$linkOnlyObservation = SvAmazonSpApiEventSink::refundObservation([
    [
        'transaction_id' => 'refund-link-deferred',
        'transaction_type' => 'Refund',
        'transaction_status' => 'DEFERRED_RELEASED',
        'posted_at' => '2026-06-21T21:20:33Z',
        'total_amount' => ['amount' => '-266.70', 'currency' => 'BRL'],
        'related_identifiers' => [
            ['name' => 'ORDER_ID', 'value' => '702-5349464-0245862'],
            ['name' => 'RELEASE_TRANSACTION_ID', 'value' => 'refund-link-released'],
            ['name' => 'FINANCIAL_EVENT_GROUP_ID', 'value' => 'group-link-deferred'],
        ],
    ],
    [
        'transaction_id' => 'refund-link-released',
        'transaction_type' => 'Refund',
        'transaction_status' => 'RELEASED',
        'posted_at' => '2026-06-27T18:59:09Z',
        'total_amount' => ['amount' => '-266.70', 'currency' => 'BRL'],
        'related_identifiers' => [
            ['name' => 'ORDER_ID', 'value' => '702-5349464-0245862'],
            ['name' => 'DEFERRED_TRANSACTION_ID', 'value' => 'refund-link-deferred'],
            ['name' => 'FINANCIAL_EVENT_GROUP_ID', 'value' => 'group-link-released'],
        ],
    ],
]);

refundIdentitySame(
    '266.70',
    $linkOnlyObservation['refund_amount'] ?? null,
    'Linked DEFERRED_TRANSACTION_ID/RELEASE_TRANSACTION_ID phases must count once when REFUND_ID is absent.'
);
refundIdentitySame(
    ['refund-link-deferred', 'refund-link-released'],
    $linkOnlyObservation['transaction_ids'] ?? null,
    'Link-based lifecycle dedupe must preserve both transaction IDs as evidence.'
);

echo "refund-lifecycle-identity-dedupe-test: ok\n";
