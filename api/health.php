<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/amazon-returns/Runtime.php';
require_once dirname(__DIR__) . '/includes/amazon-returns/TenantRegistry.php';
require_once dirname(__DIR__) . '/includes/amazon-returns/TenantPersistence.php';
require_once dirname(__DIR__) . '/includes/amazon-returns/PublicHealthResponse.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $db=amazon_returns_require_pdo();
    $config=new SvAmazonReturnsConfig();
    $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
    $persistence=SvAmazonTenantPersistence::create($db,$context);
    $health=SvAmazonReturnsRuntime::health($persistence,$config);
    echo json_encode(
        SvAmazonReturnsPublicHealthResponse::fromRuntime($health),
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(
        SvAmazonReturnsPublicHealthResponse::fromRuntime(['status'=>'FAILED']),
        JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE
    );
}
