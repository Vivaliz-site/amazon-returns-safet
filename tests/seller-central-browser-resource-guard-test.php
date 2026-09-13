<?php
declare(strict_types=1);

function rgAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$unit = (string) file_get_contents(dirname(__DIR__) . '/deploy/systemd/amazon-returns-seller-central-browser.service');
$expected = [
    'CPUWeight=20',
    'CPUQuota=60%',
    'IOWeight=20',
    'Nice=10',
    'MemoryHigh=2G',
    'MemoryMax=3G',
    'TasksMax=384',
];
foreach ($expected as $line) {
    rgAssert(str_contains($unit, $line), "Seller Central browser service must define $line.");
}

echo "seller-central-browser-resource-guard-test: OK\n";
