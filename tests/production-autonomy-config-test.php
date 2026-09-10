<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';
$cadence=SvAmazonReturnsRuntime::cadences();
foreach(['gmail','gmail_refund_reconciliation','financial','sp_api','returns_report','scheduler','seller_central','policy_monitor'] as $task){
    if(($cadence[$task]??null)!==43200)throw new RuntimeException($task.' routine must run twice per day.');
}
if(($cadence['review_operations']??null)!==7200){
    throw new RuntimeException('Internal review follow-up remains independent from business polling.');
}
$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
if(!str_contains($daemon,'gmail_refund_reconciliation') || !str_contains($daemon,'reembolso iniciado')){
    throw new RuntimeException('Gmail buyer-refund reconciliation must remain explicit in the daemon.');
}
if(!str_contains($daemon,"unset(\$state['sp_api'],\$state['financial'])")){
    throw new RuntimeException('A due action may force fresh financial data instead of using stale data.');
}
$runtimeSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
if(!str_contains($runtimeSource,"'known_action_wake'")){
    throw new RuntimeException('Known next-action timestamps must wake evidence, scheduler and delivery channels without periodic five-minute sweeps.');
}
if(!str_contains($daemon,"'next_action_at'=>null")){
    throw new RuntimeException('Scheduler must clear a consumed next-action timestamp to avoid repeated due-trigger execution.');
}
$deployTimer=(string)file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-deploy.timer');
if(str_contains($deployTimer,'OnUnitActiveSec=300') || str_contains($deployTimer,'every five minutes')){
    throw new RuntimeException('No deploy poll may run every five minutes.');
}
if(!str_contains($deployTimer,'OnUnitActiveSec=3600')){
    throw new RuntimeException('Automatic deploy polling must run hourly.');
}
$script=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
if(!str_contains($script,"set_env_key 'AMAZON_RETURNS_LEARNED_RULE_EXECUTION' '1'")){
    throw new RuntimeException('Production deploy must enable learned-rule execution after guarded rule infrastructure is active.');
}
if(!str_contains($script,"set_env_key 'AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL' 'fredmourao@gmail.com'")){
    throw new RuntimeException('Production deploy must persist the approved human-review reminder recipient.');
}
$verifier=(string)file_get_contents(__DIR__.'/../scripts/verify-live-tenant-foundation.sh');
if(!str_contains($verifier,"safet-full-recovery-v2"))throw new RuntimeException('Live verifier must accept current write-profile version v2.');
foreach(['review_notification_ready','learned_rule_execution_enabled=1'] as $needle){
    if(!str_contains($verifier,$needle))throw new RuntimeException('Live verifier must enforce production autonomy gate '.$needle);
}
echo "production-autonomy-config-test: OK\n";
