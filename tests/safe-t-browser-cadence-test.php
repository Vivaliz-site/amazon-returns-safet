<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/SafeTStatusService.php';

function cadenceSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new RuntimeException($message);
}
function cadenceNotSame(mixed $left, mixed $right, string $message): void {
    if ($left === $right) throw new RuntimeException($message);
}

$start = new DateTimeImmutable('2026-09-07T00:05:00Z');
$inside = $start->modify('+5 hours');
$next = $start->modify('+6 hours 5 minutes');
$a = SvAmazonSafeTStatusService::readKey(77, '98143-99485-9285859', $start);
$b = SvAmazonSafeTStatusService::readKey(77, '98143-99485-9285859', $inside);
$c = SvAmazonSafeTStatusService::readKey(77, '98143-99485-9285859', $next);
cadenceSame($a, $b, 'Browser status safety-net must not enqueue the same claim more than once per six-hour bucket.');
cadenceNotSame($a, $c, 'Browser status safety-net must become eligible again in the next six-hour bucket.');
echo "safe-t-browser-cadence-test: OK\n";
