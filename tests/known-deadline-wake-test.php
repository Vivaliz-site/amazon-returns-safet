<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function kdAssert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function kdSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual) throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$now=new DateTimeImmutable('2026-09-10T03:00:00Z');
kdAssert(!SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T03:00:01Z'],$now),'Known action must not trigger before its timestamp.');
kdAssert(SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T03:00:00Z'],$now),'Known action must trigger when the scheduler has not evaluated the deadline.');
kdAssert(SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T03:00:00Z','scheduler'=>'2026-09-10T02:59:59Z'],$now),'A scheduler run before the deadline must not suppress the due action.');
kdAssert(!SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T03:00:00Z','scheduler'=>'2026-09-10T03:00:00Z'],$now),'A scheduler run at the deadline marks that deadline as evaluated.');
kdAssert(!SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T02:59:00Z','scheduler'=>'2026-09-10T03:00:00Z'],$now),'A later scheduler run must prevent a tight loop.');
kdAssert(!SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'invalid'],$now),'Invalid wake timestamp must not create a tight loop.');

$state=[];
foreach(SvAmazonReturnsRuntime::cadences() as $task=>$seconds)$state[$task]=$now->format(DATE_ATOM);
$state['scheduler']='2026-09-10T02:59:59Z';
$state['next_known_action_at']='2026-09-10T03:00:00Z';
$due=SvAmazonReturnsRuntime::dueTasks($state,$now);
foreach(['known_action_wake','gmail','sp_api','financial','scheduler','seller_central'] as $task){
    kdAssert(in_array($task,$due,true),'Known-date wake must request '.$task.' without waiting for the routine 12-hour cadence.');
}
$ordered=SvAmazonReturnsRuntime::decisionSafeOrder($due);
kdSame(['bootstrap','gmail','sp_api','financial','scheduler','gmail','seller_central'],$ordered,'Known-date execution must refresh evidence, decide, then drain both possible write channels in the same cycle.');

kdSame('2026-09-10T02:58:00+00:00',SvAmazonReturnsRuntime::nextKnownWakeAt([
    ['next_action_at'=>'2026-09-10T03:15:00Z','updated_at'=>'2026-09-10T02:00:00Z','closed_at'=>null],
    ['next_action_at'=>'2026-09-10T02:59:00Z','updated_at'=>'2026-09-10T03:00:00Z','closed_at'=>null],
    ['next_action_at'=>'2026-09-10T02:58:00Z','updated_at'=>'2026-09-10T03:00:00Z','closed_at'=>null],
    ['next_action_at'=>'2026-09-10T02:30:00Z','updated_at'=>'2026-09-10T02:00:00Z','closed_at'=>'2026-09-10T02:45:00Z'],
]),'Discovery must keep the earliest open deadline even when unrelated evidence updated the case after it; scheduler state decides whether it was evaluated.');
kdSame(null,SvAmazonReturnsRuntime::nextKnownWakeAt([
    ['next_action_at'=>null,'updated_at'=>'2026-09-10T03:00:00Z','closed_at'=>null],
    ['next_action_at'=>'2026-09-10T03:15:00Z','updated_at'=>'2026-09-10T03:00:00Z','closed_at'=>'2026-09-10T03:01:00Z'],
]),'Closed or undated cases must not create a wake.');

$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
kdAssert(str_contains($runtime,'$p->cases->openCases(1000)'),'Daemon bootstrap must discover known business timestamps through tenant-scoped persistence.');
kdAssert(!str_contains($runtime,'FROM amazon_return_cases'),'Runtime must not bypass tenant repositories with direct case SQL.');
kdAssert(str_contains($runtime,"'known_action_wake'"),'Known-date execution must be an event marker, not a shorter periodic business cadence.');

$memory=(string)file_get_contents(__DIR__.'/../docs/MEMORIA-DO-PROJETO.md');
kdAssert(str_contains($memory,'sem esperar o próximo ciclo de 12 horas'),'Project memory must preserve exact known-date execution independently from routine polling.');
$rules=(string)file_get_contents(__DIR__.'/../docs/REGRAS-DE-ENTREGA.md');
kdAssert(str_contains($rules,'teste funcional de ponta a ponta') && str_contains($rules,'não pode ser considerada concluída'),'Delivery rules must explicitly require end-to-end functional validation before completion.');

echo "known-deadline-wake-test: OK\n";
