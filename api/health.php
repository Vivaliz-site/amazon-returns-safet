<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/amazon-returns/Runtime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $db=amazon_returns_require_pdo();
    $config=new SvAmazonReturnsConfig();
    $health=SvAmazonReturnsRuntime::health($db,$config);
    echo json_encode(['service'=>'amazon-returns-safet']+$health,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['service'=>'amazon-returns-safet','status'=>'FAILED'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
