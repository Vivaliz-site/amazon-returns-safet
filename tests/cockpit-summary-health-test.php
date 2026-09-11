<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/amazon-returns/CockpitHealth.php';

function cshSame(mixed $want,mixed $got,string $why):void{
    if($want!==$got)throw new RuntimeException($why.' want='.json_encode($want).' got='.json_encode($got));
}
function cshAssert(bool $ok,string $why):void{
    if(!$ok)throw new RuntimeException($why);
}

cshSame('NORMAL',SvAmazonCockpitHealth::operatorStatus(0,0),'Healthy automation must be NORMAL.');
cshSame('DEGRADED',SvAmazonCockpitHealth::operatorStatus(0,2),'Operational faults without human work must be DEGRADED.');
cshSame('USER_ACTION_REQUIRED',SvAmazonCockpitHealth::operatorStatus(1,2),'Human action takes precedence.');

$summary=(string)file_get_contents($root.'/admin/amazon-returns/api/summary.php');
foreach(['as_of','operator_status','human_action_count','automatic_work_count','concluded_count','operational_problem_count','last_successful_cycle_at','connectors','operational_problems','automation_preview','deadlines'] as $key){
    cshAssert(str_contains($summary,"'{$key}'"),'Summary contract missing '.$key);
}
$daemon=(string)file_get_contents($root.'/workers/amazon-returns/daemon.php');
cshAssert(str_contains($daemon,"'OPERATIONAL_TASK'"),'Daemon must persist per-task operational observation.');
cshAssert(str_contains($daemon,"'cycle_success'"),'Daemon must persist the last fully successful cycle.');

$runtime=(string)file_get_contents($root.'/includes/amazon-returns/Runtime.php');
cshAssert(str_contains($runtime,"'review_operations'=>7200"),'Review operations cadence must remain two hours.');
foreach(['gmail','gmail_refund_reconciliation','scheduler','seller_central','financial','sp_api','returns_report','policy_monitor'] as $task){
    cshAssert(
        preg_match("/'".preg_quote($task,'/')."'=>43200/",$runtime)===1,
        $task.' cadence must remain 12 hours.'
    );
}

$caseRepo=(string)file_get_contents($root.'/includes/amazon-returns/CaseRepository.php');
foreach(['concluded_cases','automatic_work_cases','automationPreview','upcomingDeadlines'] as $needle){
    cshAssert(str_contains($caseRepo,$needle),'Case repository summary contract missing '.$needle);
}

cshAssert(str_contains($summary,"'breakdown'"),'Summary must preserve detailed financial breakdown.');
cshAssert(str_contains($summary,"'awaiting_credit'"),'Summary must expose awaiting-credit headline.');
cshAssert(str_contains($summary,"'in_dispute'"),'Summary must expose in-dispute headline.');

echo "cockpit-summary-health-test: OK\n";
