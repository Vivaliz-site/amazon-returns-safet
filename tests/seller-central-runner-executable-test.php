<?php
$root = dirname(__DIR__);
$runner = $root . '/scripts/amazon-returns/run-seller-central-daily.sh';

if (!is_file($runner)) {
    fwrite(STDERR, "Seller Central daily runner is missing.\n");
    exit(1);
}

if (!is_executable($runner)) {
    fwrite(STDERR, "Seller Central daily runner must be executable for systemd ExecStart.\n");
    exit(1);
}

echo "seller-central-runner-executable-test: ok\n";
