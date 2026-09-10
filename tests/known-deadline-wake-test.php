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
kdAssert(in_array('scheduler',$due,true),'A due persisted business date must wake the scheduler even when its 12-hour sweep is not due.');

kdSame('2026-09-10T03:15:00+00:00',SvAmazonReturnsRuntime::nextKnownActionAt([
    null,'invalid','2026-09-10T03:30:00Z','2026-09-10T03:15:00Z','2026-09-10T02:00:00Z'
],$now),'Runtime must remember the earliest future action only.');
kdSame(null,SvAmazonReturnsRuntime::nextKnownActionAt(['2026-09-10T02:00:00Z',null],$now),'Consumed/past timestamps must not keep waking the scheduler.');

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
kdAssert(str_contains($daemon,"'next_known_action_at'"),'Daemon must persist the next known business wake timestamp after scheduler evaluation.');
kdAssert(str_contains($daemon,"'delivery_tasks_requested'"),'A write queued by a date-triggered scheduler run must request its delivery channel immediately.');
kdAssert(str_contains($daemon,"runTriggeredDeliveryTasks"),'Date-triggered writes must be drained in the same daemon cycle instead of waiting up to 12 hours.');

$memory=(string)file_get_contents(__DIR__.'/../docs/MEMORIA-DO-PROJETO.md');
kdAssert(str_contains($memory,'sem esperar o próximo ciclo de 12 horas'),'Project memory must preserve exact known-date execution independently from routine polling.');
$rules=(string)file_get_contents(__DIR__.'/../docs/REGRAS-DE-ENTREGA.md');
kdAssert(str_contains($rules,'teste funcional de ponta a ponta') && str_contains($rules,'não pode ser considerada concluída'),'Delivery rules must explicitly require end-to-end functional validation before completion.');

echo "known-deadline-wake-test: OK\n";
