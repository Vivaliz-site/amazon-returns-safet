<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/Config.php';
require_once __DIR__ . '/../includes/amazon-returns/PolicySeeder.php';
require_once __DIR__ . '/../includes/amazon-returns/Runtime.php';

function rtSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));}
function rtAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}

$defaults=new SvAmazonReturnsConfig([]);
rtSame(false,$defaults->enabled(),'Master switch defaults off.');
rtSame('dry-run',$defaults->mode(),'Mode defaults dry-run.');
foreach(['gmail_ingest','safe_t_write','appeal_write','email_review_write','email_reply_write','support_write','policy_monitor'] as $flag)rtSame(false,$defaults->flag($flag),$flag.' defaults off.');
$enabled=new SvAmazonReturnsConfig(['AMAZON_RETURNS_ENABLED'=>'1','AMAZON_RETURNS_MODE'=>'production','AMAZON_RETURNS_SAFE_T_WRITE'=>'1','AMAZON_RETURNS_APPEAL_WRITE'=>'1','AMAZON_RETURNS_EMAIL_REVIEW_WRITE'=>'1','AMAZON_RETURNS_EMAIL_REPLY_WRITE'=>'1','AMAZON_RETURNS_SUPPORT_WRITE'=>'1','AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH'=>'0']);
foreach([
 'SAFE_T_SUBMIT'=>'SAFE-T submit',
 'SAFE_T_APPEAL'=>'SAFE-T appeal',
 'SAFE_T_EMAIL_REVIEW'=>'deterministic SAFE-T email review',
 'SAFE_T_EMAIL_REPLY'=>'deterministic SAFE-T email reply',
 'SELLER_SUPPORT_OPEN'=>'Seller Support escalation',
 'SELLER_SUPPORT_UPDATE'=>'Seller Support follow-up',
] as $action=>$label)rtSame(true,$enabled->externalWriteAllowed($action),'Versioned profile enables '.$label.'.');
rtSame(false,$enabled->externalWriteAllowed('DELETE'),'Unknown action never writes.');

$policies=SvAmazonReturnPolicySeeder::definitions();
rtSame(3,count($policies),'BR policy seed count.');
foreach($policies as $policy){
    rtSame(45,(int)$policy['eligibility_days'],'ShopVivaliz first operational opening must be D+45.');
    rtAssert(preg_match('/^[a-f0-9]{64}$/',(string)$policy['source_hash'])===1,'Policy hash required.');
}

$service=(string)file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-safet.service');
foreach(['/home/ubuntu/amazon-returns-deploy/current','/home/ubuntu/amazon-returns-deploy/shared/.env'] as $needle)rtAssert(str_contains($service,$needle),'Standalone service missing '.$needle);
rtAssert(!str_contains($service,'shopvivaliz-deploy'),'Standalone service cannot use website deployment.');
$installer=(string)file_get_contents(__DIR__.'/../scripts/install-service.sh');
rtAssert(str_contains($installer,'amazon-returns-safet.service'),'Installer must own standalone service.');
rtAssert(!str_contains($installer,'SHOPVIVALIZ_DEPLOY_ROOT'),'Installer cannot use website deploy root.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
rtAssert(str_contains($daemon,'SvAmazonTenantRegistry::resolveCurrent'),'Daemon must resolve a tenant connection.');
rtAssert(str_contains($daemon,'SvAmazonTenantPersistence'),'Daemon must use scoped persistence.');
rtAssert(str_contains($daemon,'$this->persistence->outbox->claimBatch'),'Daemon must claim only through the scoped outbox.');
rtAssert(str_contains($daemon,"['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE']"),'Seller Central worker action allowlist.');
rtAssert(str_contains($daemon,'amazon_returns_pdo()'),'Daemon must use standalone DB bootstrap.');
rtAssert(str_contains($daemon,'listSafeTReimbursements'),'Daemon must read documented Finances v0 SAFE-T reimbursements.');
rtAssert(str_contains($daemon,'persistSafeTReimbursements'),'Daemon must persist SAFE-T reimbursements through tenant-scoped stores.');
rtAssert(str_contains($daemon,"unset(\$state['sp_api'],\$state['financial'])"),'Known-date decisions may force a fresh financial read when they are evaluated.');
$runtimeSource=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
rtAssert(str_contains($runtimeSource,"'known_action_wake'"),'Known next-action dates must trigger the full due-date wake without five-minute polling.');
rtAssert(str_contains($daemon,"'next_action_at'=>null"),'Consumed next-action dates must be cleared so a due trigger does not spin forever.');
rtAssert(!str_contains($daemon,'EventStore.php'),'Daemon cannot load global EventStore.');
rtAssert(!str_contains($daemon,'Outbox.php'),'Daemon cannot load global Outbox.');
rtAssert(!str_contains($daemon,'config/constants.php'),'Daemon cannot load website constants.');
rtAssert(!str_contains($daemon,'includes/pdo-database.php'),'Daemon cannot load website DB layer.');

$cadence=SvAmazonReturnsRuntime::cadences();
foreach(['gmail','gmail_refund_reconciliation','financial','sp_api','returns_report','scheduler','seller_central','policy_monitor'] as $task){
    rtSame(43200,$cadence[$task],$task.' routine must run twice per day.');
}
rtSame(7200,$cadence['review_operations'],'Pending human-review follow-up must run every two hours.');
rtSame(900,$cadence['health'],'Health monitoring remains frequent and is not an external business routine.');

$now=new DateTimeImmutable('2026-09-10T12:00:00Z');
rtSame(false,SvAmazonReturnsRuntime::knownActionDue([], $now),'No known timestamp means no date-triggered scheduler run.');
rtSame(false,SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T13:00:00Z'],$now),'Future action must not trigger early.');
rtSame(true,SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T12:00:00Z','scheduler'=>'2026-09-10T11:59:59Z'],$now),'Known action must trigger at its exact due time.');
rtSame(false,SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T12:00:00Z','scheduler'=>'2026-09-10T12:00:00Z'],$now),'Scheduler execution at the deadline must mark that deadline evaluated.');
rtSame(true,SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T11:59:59Z'],$now),'Overdue known action must trigger when not yet evaluated.');
rtSame(false,SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'invalid'],$now),'Invalid date must not create a tight loop.');

// Regression for a corrupt, forward-dated persisted runtime marker. None of
// the normal taskScheduleMarker() writers is allowed to schedule in the future.
// This must not silently suspend evidence tasks or scheduler forever.
$futureState=[];
foreach(SvAmazonReturnsRuntime::cadences() as $task=>$seconds){
    $futureState[$task]='2099-01-01T00:00:00Z';
}
$recovered=SvAmazonReturnsRuntime::dueTasks($futureState,$now);
foreach(array_keys(SvAmazonReturnsRuntime::cadences()) as $task){
    rtAssert(in_array($task,$recovered,true),$task.' must recover from a far-future runtime marker.');
}
$nearFuture=$now->modify('+300 seconds')->format(DATE_ATOM);
rtAssert(!in_array('gmail',SvAmazonReturnsRuntime::dueTasks(['gmail'=>$nearFuture],$now),true),
    'Up to five minutes of forward clock skew must not cause an extra Gmail run.');
$normalMarker=SvAmazonReturnsRuntime::taskScheduleMarker('gmail',$now);
rtAssert(!in_array('gmail',SvAmazonReturnsRuntime::dueTasks(['gmail'=>$normalMarker],$now),true),
    'Normal Gmail completion must still respect the 12-hour cadence.');
rtAssert(in_array('gmail',SvAmazonReturnsRuntime::dueTasks(['gmail'=>$normalMarker],$now->modify('+43200 seconds')),true),
    'Normal 12-hour Gmail cadence must still wake exactly on schedule.');

// Coarse, non-secret schedule observability: prove actual reason for a task
// not being due without exposing the protected timestamp or state contents.
$diag=SvAmazonReturnsRuntime::cadenceDiagnostics([
    'gmail'=>'2099-01-01T00:00:00Z',
    'sp_api'=>$now->format(DATE_ATOM),
    'scheduler'=>$now->modify('-43200 seconds')->format(DATE_ATOM),
    'financial'=>$now->modify('+30 seconds')->format(DATE_ATOM),
],$now);
rtSame('FUTURE_INVALID',$diag['gmail'],'Future marker must be identified safely.');
rtSame('RECENT',$diag['sp_api'],'Recent markers must be identified safely.');
rtSame('DUE',$diag['scheduler'],'Overdue markers must be identified safely.');
rtSame('CLOCK_SKEW',$diag['financial'],'Short forward clock drift is diagnostic only.');
rtSame('MISSING_OR_INVALID',$diag['gmail_refund_reconciliation'],'Missing marker has a safe category.');
rtSame(count(SvAmazonReturnsRuntime::cadences()),count($diag),'Only known cadence tasks can be reported.');
$encoded=json_encode($diag,JSON_THROW_ON_ERROR);
rtAssert(!str_contains($encoded,'2099') && !str_contains($encoded,'2026-'),
    'Diagnostics must not leak persisted timestamps.');
$daemonSource=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
rtAssert(str_contains($daemonSource,"'cadence_diagnostics'=>\$cadenceSnapshot"),
    'Daemon must emit categorized evidence when its cycle completes.');

echo "amazon-returns-runtime-test: OK\n";
