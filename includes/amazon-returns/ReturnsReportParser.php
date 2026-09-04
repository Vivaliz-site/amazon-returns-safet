<?php

declare(strict_types=1);

require_once __DIR__ . '/Enums.php';

/**
 * Pure parser for the official GET_FLAT_FILE_RETURNS_DATA_BY_RETURN_DATE flat
 * file. Column layout confirmed against a real Amazon-generated report.
 */
final class SvAmazonReturnsReportParser
{
    /** @return list<array<string,string>> */
    public static function parse(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
            if (is_string($converted)) $content = $converted;
        }

        $lines = array_values(array_filter(
            explode("\n", $content),
            static fn(string $line): bool => trim($line) !== ''
        ));
        if ($lines === []) return [];

        $header = array_map('trim', explode("\t", (string) array_shift($lines)));
        $rows = [];
        foreach ($lines as $line) {
            $columns = explode("\t", $line);
            $row = [];
            foreach ($header as $index => $name) {
                if ($name === '') continue;
                $row[$name] = trim((string) ($columns[$index] ?? ''));
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** @param array<string,string> $row */
    public static function orderId(array $row): string
    {
        return trim((string) ($row['Order ID'] ?? ''));
    }

    /** @param array<string,string> $row */
    public static function orderItemId(array $row): string
    {
        return trim((string) ($row['Order Item ID'] ?? ''));
    }

    /**
     * Only returns a confident classification; anything ambiguous stays
     * UNKNOWN rather than guessing.
     *
     * @param array<string,string> $row
     */
    public static function refundInitiatorFromRow(array $row): string
    {
        $aToZ = strtoupper(trim((string) ($row['A-to-Z Claim'] ?? '')));
        if ($aToZ === 'Y') {
            return SvAmazonRefundInitiators::A_TO_Z;
        }

        $resolution = strtoupper(trim((string) ($row['Resolution'] ?? '')));
        if ($resolution !== '' && str_contains($resolution, 'REFUNDATFIRSTSCAN')) {
            return SvAmazonRefundInitiators::AMAZON_AUTOMATIC;
        }

        return SvAmazonRefundInitiators::UNKNOWN;
    }
}
