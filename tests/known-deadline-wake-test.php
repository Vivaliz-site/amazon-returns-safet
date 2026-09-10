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
kdAssert(SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T03:00:00Z'],$now),'Known action must trigger at its timestamp.');
kdAssert(SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'2026-09-10T02:59:59Z'],$now),'Overdue known action must trigger immediately.');
kdAssert(!SvAmazonReturnsRuntime::knownActionDue(['next_known_action_at'=>'invalid'],$now),'Invalid wake timestamp must not create a tight loop.');

$state=[];
foreach(SvAmazonReturnsRuntime::cadences() as $task=>$seconds)$state[$task]=$now->format(DATE_ATOM);
$state['next_known_action_at']='2026-09-10T03:00:00Z';
$due=SvAmazonReturnsRuntime::dueTasks($state,$now);
foreach(['known_action_wake','gmail','sp_api','financial','scheduler','seller_central'] as $task){
    kdAssert(in_array($task,$due,true),'Known-date wake must request '.$task.' without waiting for the routine 12-hour cadence.');
}
$ordered=SvAmazonReturnsRuntime::decisionSafeOrder($due);
kdSame(['bootstrap','gmail','sp_api','financial','scheduler','gmail','seller_central'],$ordered,'Known-date execution must refresh evidence, decide, then drain both possible write channels in the same cycle.');

kdSame('2026-09-10T03:15:00+00:00',SvAmazonReturnsRuntime::nextKnownActionAt([
    null,'invalid','2026-09-10T03:30:00Z','2026-09-10T03:15:00Z','2026-09-10T02:00:00Z'
],$now),'Runtime must remember the earliest future action only.');
kdSame(null,SvAmazonReturnsRuntime::nextKnownActionAt(['2026-09-10T02:00:00Z',null],$now),'Consumed/past timestamps must not be treated as future wakes.');

$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
kdAssert(str_contains($runtime,'SELECT MIN(next_action_at)'),'Daemon bootstrap must discover the earliest known business timestamp from the local scoped case store.');
kdAssert(str_contains($runtime,'updated_at<next_action_at'),'A due timestamp already evaluated after its deadline must not create a tight loop.');
kdAssert(str_contains($runtime,"'known_action_wake'"),'Known-date execution must be an event marker, not a shorter periodic business cadence.');

$memory=(string)file_get_contents(__DIR__.'/../docs/MEMORIA-DO-PROJETO.md');
kdAssert(str_contains($memory,'sem esperar o próximo ciclo de 12 horas'),'Project memory must preserve exact known-date execution independently from routine polling.');
$rules=(string)file_get_contents(__DIR__.'/../docs/REGRAS-DE-ENTREGA.md');
kdAssert(str_contains($rules,'teste funcional de ponta a ponta') && str_contains($rules,'não pode ser considerada concluída'),'Delivery rules must explicitly require end-to-end functional validation before completion.');

echo "known-deadline-wake-test: OK\n";
