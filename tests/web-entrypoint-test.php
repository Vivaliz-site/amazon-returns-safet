<?php
declare(strict_types=1);

function weAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$index=__DIR__.'/../index.php';
$health=__DIR__.'/../api/health.php';
weAssert(is_file($index),'Root web entrypoint must exist.');
weAssert(is_file($health),'Standalone health endpoint must exist.');
$i=(string)file_get_contents($index);
$h=(string)file_get_contents($health);
weAssert(str_contains($i,'SvAmazonReturnsAdminAuth'),'Root entrypoint must use standalone admin auth.');
weAssert(str_contains($i,'/admin/amazon-returns/'),'Authenticated root must reach dashboard.');
weAssert(str_contains($h,'amazon_returns_require_pdo'),'Health must use standalone DB bootstrap.');
weAssert(str_contains($h,'SvAmazonTenantRegistry::resolveCurrent'),'Health must resolve the current tenant server-side.');
weAssert(str_contains($h,'SvAmazonTenantPersistence::create'),'Health must bind runtime queries to tenant persistence.');
weAssert(str_contains($h,'SvAmazonReturnsRuntime::health'),'Health must report real runtime state.');
weAssert(!str_contains($h,'shopvivaliz'),'Health must not depend on website runtime.');

echo "web-entrypoint-test: OK\n";
