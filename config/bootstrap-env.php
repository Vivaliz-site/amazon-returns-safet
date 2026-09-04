<?php
declare(strict_types=1);

$envFile = getenv('AMAZON_RETURNS_ENV_FILE');
if (!is_string($envFile) || trim($envFile) === '') {
    $envFile = '/home/ubuntu/amazon-returns-deploy/shared/.env';
}
if (!is_readable($envFile)) return;

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!is_array($lines)) return;
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $key = trim(substr($line, 0, $pos));
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) continue;
    if (getenv($key) !== false) continue;
    $value = trim(substr($line, $pos + 1));
    if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
        $value = substr($value, 1, -1);
    }
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}
