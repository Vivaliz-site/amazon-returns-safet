<?php
declare(strict_types=1);
$test = __DIR__ . '/amazon-returns-read-worker-drain.test.mjs';
$command = 'node --test ' . escapeshellarg($test) . ' 2>&1';
exec($command, $output, $exitCode);
if ($exitCode !== 0) {
    throw new RuntimeException("Read worker drain regression failed:\n" . implode("\n", $output));
}
echo "amazon-returns-read-worker-drain-test: OK\n";
