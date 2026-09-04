<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/bootstrap-env.php';

function amazon_returns_pdo(): ?PDO
{
    static $pdo = null;
    static $failed = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($failed) return null;

    $host = trim((string)(getenv('AMAZON_RETURNS_DB_HOST') ?: '127.0.0.1'));
    $port = trim((string)(getenv('AMAZON_RETURNS_DB_PORT') ?: '3306'));
    $name = trim((string)(getenv('AMAZON_RETURNS_DB_NAME') ?: 'amazon_returns_safet'));
    $user = trim((string)(getenv('AMAZON_RETURNS_DB_USER') ?: ''));
    $pass = (string)(getenv('AMAZON_RETURNS_DB_PASS') ?: '');
    $charset = trim((string)(getenv('AMAZON_RETURNS_DB_CHARSET') ?: 'utf8mb4'));
    if ($user === '' || $name === '') { $failed = true; return null; }

    $dsn = $host === 'localhost' || $host === '127.0.0.1'
        ? sprintf('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=%s;charset=%s', $name, $charset)
        : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
        $pdo->query('SELECT 1')->fetchColumn();
        return $pdo;
    } catch (Throwable $e) {
        $failed = true;
        error_log('[amazon-returns-db] connection unavailable');
        return null;
    }
}

function amazon_returns_require_pdo(): PDO
{
    $db = amazon_returns_pdo();
    if (!$db instanceof PDO) throw new RuntimeException('Amazon Returns database unavailable.');
    return $db;
}
