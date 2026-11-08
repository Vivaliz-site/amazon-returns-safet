<?php
declare(strict_types=1);

/**
 * Distinguish a CLI running outside the protected service account from
 * a genuine MySQL outage, without ever reading production credentials.
 */
$log = tempnam(sys_get_temp_dir(), 'svcr-db-context-');
if ($log === false) {
    fwrite(STDERR, "FAIL: temporary log unavailable\n");
    exit(1);
}
$priorLog = (string)ini_get('error_log');
$priorErrors = (string)ini_get('log_errors');
try {
    ini_set('error_log', $log);
    ini_set('log_errors', '1');
    // No production .env and no DB_USER. These overrides are local to this PHP
    // process; there must be no network or database connection attempt.
    putenv('AMAZON_RETURNS_ENV_FILE=/dev/null');
    putenv('AMAZON_RETURNS_DB_USER=');
    require_once dirname(__DIR__) . '/includes/Database.php';

    if (amazon_returns_pdo() !== null) {
        throw new RuntimeException('Missing credentials unexpectedly connected');
    }
    try {
        amazon_returns_require_pdo();
        throw new RuntimeException('Missing credentials unexpectedly accepted');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'Amazon Returns database unavailable.') {
            throw $e;
        }
    }

    $logged = (string)file_get_contents($log);
    if (substr_count($logged, 'DB_RUNTIME_CONTEXT_MISSING') !== 1) {
        throw new RuntimeException('Missing protected service context must be logged once with a distinct code');
    }
    if (str_contains($logged, 'connection unavailable')) {
        throw new RuntimeException('Missing service context must not be misreported as MySQL connection failure');
    }
    echo "DB_RUNTIME_CONTEXT_DIAGNOSTIC_PASS\n";
} finally {
    ini_set('error_log', $priorLog);
    ini_set('log_errors', $priorErrors);
    @unlink($log);
}
