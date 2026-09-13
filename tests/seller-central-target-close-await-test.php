<?php
declare(strict_types=1);
function tcAssert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$root = dirname(__DIR__);
$workers = [
    'read' => $root . '/scripts/amazon-returns/seller-central-safe-t-read-worker.mjs',
    'bridge' => $root . '/scripts/amazon-returns/seller-central-bridge-worker.mjs',
];
foreach ($workers as $name => $path) {
    $source = (string) file_get_contents($path);
    $helperStart = strpos($source, 'async function closeCdpTarget(targetId) {');
    $helperEnd = $helperStart === false ? false : strpos($source, "\n}\n\nclass Cdp", $helperStart);
    tcAssert($helperStart !== false && $helperEnd !== false, "$name worker must define bounded target cleanup.");
    $helper = substr($source, (int)$helperStart, (int)$helperEnd - (int)$helperStart);
    tcAssert(str_contains($helper, '/json/close/'), "$name cleanup must close the CDP target.");
    tcAssert(str_contains($helper, 'AbortSignal.timeout(2500)'), "$name cleanup must have a 2.5s timeout.");
    $connectStart = strpos($source, '  static async connect() {');
    $connectEnd = $connectStart === false ? false : strpos($source, "\n  send(", $connectStart);
    tcAssert($connectStart !== false && $connectEnd !== false, "$name connect block must remain auditable.");
    $connect = substr($source, (int)$connectStart, (int)$connectEnd - (int)$connectStart);
    tcAssert(str_contains($connect, 'await closeCdpTarget(page.id)'), "$name connect failure must remove its target.");
    tcAssert(!preg_match('/(?<!await )\bcdp\.close\(\);/', $source), "$name worker must await cdp.close().");
}
$bridge = (string) file_get_contents($workers['bridge']);
tcAssert(!preg_match('/(?<!await )\blookup\.close\(\);/', $bridge), 'bridge worker must await lookup.close().');
echo "seller-central-target-close-await-test: OK\n";
