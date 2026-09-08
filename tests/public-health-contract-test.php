<?php
declare(strict_types=1);

$presenterPath = __DIR__ . '/../includes/amazon-returns/PublicHealthResponse.php';
if (!is_file($presenterPath)) {
    throw new RuntimeException('Public health response presenter must exist.');
}
require_once $presenterPath;

function phrSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

$detailed = [
    'status'=>'OK',
    'tenant_id'=>1,
    'amazon_connection_id'=>2,
    'tables'=>18,
    'cases'=>40,
    'pending_outbox'=>3,
    'dead_letters'=>1,
    'pending_reviews'=>4,
    'review_ai_ready'=>true,
    'readiness'=>['sp_api'=>['ready'=>true,'missing'=>[]]],
    'write_flags'=>['SAFE_T_SUBMIT'=>true],
];

$public = SvAmazonReturnsPublicHealthResponse::fromRuntime($detailed);
phrSame(
    ['service'=>'amazon-returns-safet','status'=>'OK'],
    $public,
    'Unauthenticated health response must expose availability only.'
);

$failed = SvAmazonReturnsPublicHealthResponse::fromRuntime(['status'=>'FAILED','tenant_id'=>99]);
phrSame(
    ['service'=>'amazon-returns-safet','status'=>'FAILED'],
    $failed,
    'Failure health response must not leak tenant or operational internals.'
);

$unknown = SvAmazonReturnsPublicHealthResponse::fromRuntime(['status'=>'unexpected']);
phrSame(
    ['service'=>'amazon-returns-safet','status'=>'FAILED'],
    $unknown,
    'Unknown internal health states must fail closed.'
);

echo "public-health-contract-test: OK\n";
