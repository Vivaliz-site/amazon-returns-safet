<?php
declare(strict_types=1);

function isoAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
foreach (['config/bootstrap-env.php','includes/Database.php','includes/AdminAuth.php','includes/Csrf.php','deploy/systemd/amazon-returns-safet.service'] as $required) {
    isoAssert(is_file($root . '/' . $required), 'Missing standalone file: ' . $required);
}

$forbidden = [
    'config/constants.php',
    'includes/pdo-database.php',
    '/home/ubuntu/shopvivaliz-deploy',
    'shopvivaliz-amazon-returns.service',
    'https://shopvivaliz.com.br/api/amazon-returns',
];
$runtimeRoots = ['api','admin','includes','workers','scripts','deploy'];
foreach ($runtimeRoots as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $text = file_get_contents($file->getPathname());
        if (!is_string($text)) continue;
        foreach ($forbidden as $needle) isoAssert(!str_contains($text, $needle), $file->getPathname() . ' still depends on ' . $needle);
    }
}

echo "standalone-isolation-test: OK\n";
