<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/ReturnsReportParser.php';

function rrpAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rrpSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

// Column layout confirmed against a real GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE
// document; values below are synthetic fixtures, not real order data.
$header = "Order ID\tOrder date\tReturn request date\tReturn request status\tAmazon RMA ID\tMerchant RMA ID\t"
    . "Label type\tLabel cost\tCurrency code\tReturn carrier\tTracking ID\tLabel to be paid by\tA-to-Z Claim\t"
    . "Is prime\tASIN\tMerchant SKU\tItem Name\tReturn quantity\tReturn Reason\tIn policy\tReturn type\tResolution\t"
    . "Invoice number\tReturn delivery date\tOrder Amount\tOrder quantity\tSafeT Action reason\tSafeT claim id\t"
    . "SafeT claim state\tSafeT claim creation time\tSafeT claim reimbursement amount\tRefunded Amount\tOrder Item ID";

$autoRefundRow = "111-1111111-1111111\t01-Aug-2026\t03-Aug-2026\tApproved\tAMZ-RMA-1\t \tAmazonPrePaidLabel\t9.90\tBRL\t"
    . "Correios\tTRK123\tCustomer\tN\tN\tB000000001\tSKU-1\tProduto Teste\t1\tCR-ORDERED_WRONG_ITEM\tY\tC-Returns\t"
    . "RefundAtFirstScan\t \t \t50.00\t1\t \t \t \t \t \t50.00\t100000000000001";

$atozRow = "222-2222222-2222222\t02-Aug-2026\t04-Aug-2026\tApproved\tAMZ-RMA-2\t \tAmazonPrePaidLabel\t9.90\tBRL\t"
    . "Correios\tTRK456\tSeller\tY\tN\tB000000002\tSKU-2\tProduto Teste 2\t1\tCR-DEFECTIVE\tY\tA-to-z Guarantee\t"
    . "ReplacementIssued\t \t \t80.00\t1\t \t \t \t \t \t80.00\t100000000000002";

$ambiguousRow = "333-3333333-3333333\t03-Aug-2026\t05-Aug-2026\tApproved\tAMZ-RMA-3\t \tAmazonPrePaidLabel\t9.90\tBRL\t"
    . "Correios\tTRK789\tCustomer\tN\tN\tB000000003\tSKU-3\tProduto Teste 3\t1\tCR-NO_LONGER_NEEDED\tY\tC-Returns\t"
    . "ManualRefund\t \t \t30.00\t1\t \t \t \t \t \t30.00\t100000000000003";

$content = implode("\n", [$header, $autoRefundRow, $atozRow, $ambiguousRow, '']);
$rows = SvAmazonReturnsReportParser::parse($content);

rrpSame(3, count($rows), 'All non-empty data rows must parse.');
rrpSame('111-1111111-1111111', SvAmazonReturnsReportParser::orderId($rows[0]), 'Order ID column must map by header name.');
rrpSame('100000000000001', SvAmazonReturnsReportParser::orderItemId($rows[0]), 'Order Item ID column must map by header name.');
rrpSame('CR-ORDERED_WRONG_ITEM', $rows[0]['Return Reason'], 'Arbitrary columns must be accessible by header name.');

rrpSame('AMAZON_AUTOMATIC', SvAmazonReturnsReportParser::refundInitiatorFromRow($rows[0]), 'RefundAtFirstScan resolution must classify as Amazon automatic.');
rrpSame('A_TO_Z', SvAmazonReturnsReportParser::refundInitiatorFromRow($rows[1]), 'A-to-Z Claim=Y must classify as A_TO_Z regardless of resolution text.');
rrpSame('UNKNOWN', SvAmazonReturnsReportParser::refundInitiatorFromRow($rows[2]), 'Unrecognized resolution must stay UNKNOWN rather than guessing.');

// CRLF line endings and a trailing blank line must not break parsing.
$crlfContent = str_replace("\n", "\r\n", $content);
$crlfRows = SvAmazonReturnsReportParser::parse($crlfContent);
rrpSame(3, count($crlfRows), 'CRLF-delimited reports must parse identically.');

rrpAssert(SvAmazonReturnsReportParser::parse('') === [], 'Empty content must parse to an empty list.');
rrpAssert(SvAmazonReturnsReportParser::parse("Order ID\tOther\n") === [], 'Header-only content must parse to an empty list.');

echo "amazon-returns-returns-report-parser-test: OK\n";
