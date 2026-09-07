<?php
declare(strict_types=1);
$installer = file_get_contents(__DIR__ . '/../scripts/install-amazon-returns-windows-bridge.ps1');
if (!is_string($installer)) { fwrite(STDERR, "installer missing\n"); exit(1); }
$fail = [];
$required = [
    'seller-central-safe-t-read-worker.mjs',
    'safe-t-status-parser.mjs',
    'ShopVivaliz Amazon Returns Browser Dispatcher',
    '--drain',
    'RepetitionInterval',
    'PollMinutes = 5',
    'EXECUTION_MODE=ON_DEMAND_DRAIN',
    'Stop-SellerCentralBrowser',
    'Stop-LegacyBridgeProcesses',
    'ShopVivaliz Amazon Returns SAFE-T Read Bridge',
    'ShopVivaliz Amazon Returns Seller Central Bridge',
];
foreach ($required as $needle) {
    if (strpos($installer, $needle) === false) $fail[] = 'missing on-demand bridge contract: ' . $needle;
}
foreach (['New-ScheduledTaskTrigger -AtStartup', 'New-ScheduledTaskTrigger -AtLogOn'] as $forbidden) {
    if (strpos($installer, $forbidden) !== false) $fail[] = 'persistent boot/logon trigger forbidden: ' . $forbidden;
}
if ($fail) { fwrite(STDERR, implode("\n", $fail) . "\n"); exit(1); }
echo "windows-bridge-on-demand-install-test: OK\n";
