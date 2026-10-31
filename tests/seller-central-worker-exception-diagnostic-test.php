<?php
declare(strict_types=1);
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
if(!str_contains($worker,"log('job_exception'")) throw new RuntimeException('Unhandled bridge exceptions must emit a sanitized diagnostic event before returning FAILED.');
if(!str_contains($worker,"error?.message")) throw new RuntimeException('Exception diagnostic must include the concrete error message needed for root-cause analysis.');
if(!str_contains($worker, 'retry_safe: true')) throw new RuntimeException('Unhandled browser exceptions must remain retry-safe for idempotent recovery.');
echo "seller-central-worker-exception-diagnostic-test: OK\n";
