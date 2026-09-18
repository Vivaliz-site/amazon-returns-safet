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
    'status'=>'OK','tenant_id'=>1,'amazon_connection_id'=>2,'tables'=>18,'cases'=>40,
    'pending_outbox'=>3,'dead_letters'=>1,'pending_reviews'=>4,'review_ai_ready'=>true,
    'readiness'=>['sp_api'=>['ready'=>true,'missing'=>[]]],'write_flags'=>['SAFE_T_SUBMIT'=>true],
];
phrSame(
    ['service'=>'amazon-returns-safet','status'=>'OK','blockers'=>[]],
    SvAmazonReturnsPublicHealthResponse::fromRuntime($detailed),
    'Unauthenticated health response must expose availability only.'
);
phrSame(
    ['service'=>'amazon-returns-safet','status'=>'DEGRADED','blockers'=>['TASK_ERP_SALES_RETURNS_PARTIAL']],
    SvAmazonReturnsPublicHealthResponse::fromRuntime(['status'=>'DEGRADED','tenant_id'=>99,'health_blockers'=>['TASK_ERP_SALES_RETURNS_PARTIAL']]),
    'Degraded dependencies must not be misreported as a total service failure.'
);
phrSame(
    ['service'=>'amazon-returns-safet','status'=>'FAILED','blockers'=>[]],
    SvAmazonReturnsPublicHealthResponse::fromRuntime(['status'=>'FAILED','tenant_id'=>99]),
    'Failure health response must not leak tenant or operational internals.'
);
phrSame(
    ['service'=>'amazon-returns-safet','status'=>'FAILED','blockers'=>[]],
    SvAmazonReturnsPublicHealthResponse::fromRuntime(['status'=>'unexpected']),
    'Unknown internal health states must fail closed.'
);

echo "public-health-contract-test: OK\n";
