<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Enums.php';
require_once __DIR__ . '/../includes/amazon-returns/ReturnsReport.php';

function rrSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}
function rrAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$header = [
    'Extra Column', 'Resolution', 'Order Item ID', 'Order ID', 'A-to-Z Claim',
    'Return request date', 'Return request status', 'Return quantity',
    'Return Reason', 'In policy', 'Return delivery date', 'SafeT claim id',
    'SafeT claim state', 'SafeT claim creation time',
    'SafeT claim reimbursement amount', 'Refunded Amount',
];
$rowAtoZ = [
    'ignored', 'RefundAtFirstScan', 'item-az', '702-1111111-2222222', 'Y',
    '20-Aug-2026', 'Approved', '1', 'CR-ORDERED-WRONG-ITEM', 'Y', '',
    '98143-99485-9285859', 'Approved', '21-Aug-2026 10:11:12', '77.98', '77,98',
];
$rowAuto = [
    'ignored-2', 'RefundAtFirstScan', 'item-auto', '702-3333333-4444444', 'N',
    '22-Aug-2026', 'Approved', '2', 'CR-DEFECTIVE', 'Y', '01-Sep-2026',
    '', '', '', '', '128.25',
];
$encode = static fn(array $row): string => implode("\t", $row);
$document = "\xEF\xBB\xBF" . $encode($header) . "\r\n" . $encode($rowAtoZ) . "\r\n" . $encode($rowAuto) . "\r\n";
$rows = SvAmazonReturnsReport::parse($document);
rrSame(2, count($rows), 'Two report rows must parse.');
rrSame('702-1111111-2222222', $rows[0]['order_id'], 'Order ID must resolve by header name.');
rrSame('item-az', $rows[0]['order_item_id'], 'Order item ID must resolve after reordered columns.');
rrSame(SvAmazonRefundInitiators::A_TO_Z, $rows[0]['refund_initiator'], 'A-to-Z=Y must be explicit initiator evidence.');
rrSame('2026-08-20 00:00:00', $rows[0]['return_request_at'], 'Amazon date must normalize to UTC.');
rrSame('77.98', $rows[0]['refunded_amount'], 'Comma decimal must normalize.');
rrSame('98143-99485-9285859', $rows[0]['safe_t_id'], 'SAFE-T ID must normalize.');
rrSame('SAFE_T_APPROVED', $rows[0]['safe_t_state'], 'Approved SAFE-T report status must normalize.');
rrSame(SvAmazonRefundInitiators::AMAZON_AUTOMATIC, $rows[1]['refund_initiator'], 'RefundAtFirstScan must identify an Amazon automatic refund.');
rrSame('2026-09-01 00:00:00', $rows[1]['return_delivery_at'], 'Delivery date must remain carrier evidence only.');
rrSame(2, $rows[1]['return_quantity'], 'Return quantity must normalize to integer.');

$patch = SvAmazonReturnsReport::casePatch($rows[0]);
rrSame(SvAmazonRefundInitiators::A_TO_Z, $patch['refund_initiator'], 'Evidence-backed initiator may patch an unknown case.');
rrSame('98143-99485-9285859', $patch['safe_t_id'], 'Report may attach observed SAFE-T ID.');
rrSame('SAFE_T_APPROVED', $patch['state'], 'Report may advance observational SAFE-T state.');
rrAssert(!array_key_exists('seller_debit_at', $patch), 'Returns report must not assert financial debit truth.');
rrAssert(!array_key_exists('reconciled_credit_amount', $patch), 'Returns report reimbursement is not Finances truth.');

$event = SvAmazonReturnsReport::eventForCase(41, $rows[0], 'DOC-1', str_repeat('a', 64));
rrSame('RETURN_REPORT_OBSERVED', $event['event_type'], 'Report rows must append an immutable domain event.');
rrSame('SP_API_REPORTS', $event['source'], 'Report event source must be explicit.');
rrAssert(preg_match('/^[a-f0-9]{64}$/', $event['idempotency_key']) === 1, 'Report event must be idempotent.');
rrSame(str_repeat('a', 64), $event['evidence_sha256'], 'Report document hash must bind the event evidence.');
rrAssert(!array_key_exists('document_content', $event['payload']), 'Raw report content must never be stored in an event.');

$window = SvAmazonReturnsReport::nextWindow(null, '2026-06-18 00:00:00', new DateTimeImmutable('2026-09-02T12:00:00Z'));
rrSame('2026-06-16T00:00:00+00:00', $window['from']->format(DATE_ATOM), 'Initial report window starts two days before earliest observed refund.');
rrSame('2026-07-15T00:00:00+00:00', $window['to']->format(DATE_ATOM), 'Initial report window is bounded to 29 days.');
$incremental = SvAmazonReturnsReport::nextWindow('2026-08-20T00:00:00Z', null, new DateTimeImmutable('2026-09-02T12:00:00Z'));
rrSame('2026-08-20T00:00:00+00:00', $incremental['from']->format(DATE_ATOM), 'High-water mark resumes without overlap.');
rrSame('2026-09-02T12:00:00+00:00', $incremental['to']->format(DATE_ATOM), 'Incremental window ends at current UTC time.');

echo "amazon-returns-report-test: OK\n";
