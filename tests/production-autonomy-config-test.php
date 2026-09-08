<?php
declare(strict_types=1);
$script=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
if(!str_contains($script,"set_env_key 'AMAZON_RETURNS_LEARNED_RULE_EXECUTION' '1'")){
    throw new RuntimeException('Production deploy must enable learned-rule execution after guarded rule infrastructure is active.');
}
if(!str_contains($script,"set_env_key 'AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL' 'fredmourao@gmail.com'")){
    throw new RuntimeException('Production deploy must persist the approved human-review reminder recipient.');
}
$verifier=(string)file_get_contents(__DIR__.'/../scripts/verify-live-tenant-foundation.sh');
foreach(['review_notification_ready','learned_rule_execution_enabled=1'] as $needle){
    if(!str_contains($verifier,$needle))throw new RuntimeException('Live verifier must enforce production autonomy gate '.$needle);
}
echo "production-autonomy-config-test: OK\n";
