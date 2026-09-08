<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';
$cadence=SvAmazonReturnsRuntime::cadences();
foreach(['gmail','gmail_refund_reconciliation','financial','sp_api','returns_report','scheduler','seller_central','policy_monitor'] as $task){
    if(($cadence[$task]??null)!==86400)throw new RuntimeException($task.' external business task must run daily.');
}
if(($cadence['review_operations']??null)!==14400){
    throw new RuntimeException('Internal review follow-up may remain every four hours.');
}
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
if(!str_contains($daemon,'gmail_refund_reconciliation') || !str_contains($daemon,'reembolso iniciado')){
    throw new RuntimeException('Daily Gmail buyer-refund reconciliation must be explicit in the daemon.');
}
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