<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function kdiAssert(bool $condition,string $message): void {
    if(!$condition) throw new RuntimeException($message);
}
function kdiSame(mixed $expected,mixed $actual,string $message): void {
    if($expected!==$actual) throw new RuntimeException($message.' Expected '.var_export($expected,true).' got '.var_export($actual,true));
}

$now=new DateTimeImmutable('2026-09-10T12:00:00Z');
kdiAssert(SvAmazonReturnsRuntime::knownActionDue([['next_action_at'=>'2026-09-10T12:00:00Z']],$now),'Known deadline must wake exactly when due.');
kdiAssert(!SvAmazonReturnsRuntime::knownActionDue([['next_action_at'=>'2026-09-10T12:00:01Z']],$now),'Known deadline must not wake early.');

$due=['bootstrap','known_action_wake','gmail','sp_api','financial','scheduler','seller_central'];
kdiSame(
    ['bootstrap','gmail','sp_api','financial','scheduler','gmail','seller_central'],
    SvAmazonReturnsRuntime::decisionSafeOrder($due),
    'Known-date wake must refresh evidence, decide, then drain email and Seller Central writes in the same cycle.'
);

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
$wakePos=strpos($daemon,"'known_action_wake'");
$safeSchedulePos=strpos($daemon,'SvAmazonFinancialRefresh::safeSchedule');
kdiAssert($wakePos!==false,'Daemon must mark known-date execution explicitly instead of hiding it as routine polling.');
kdiAssert($safeSchedulePos!==false && $wakePos<$safeSchedulePos,'Known-date evidence tasks must be requested before the financial refresh safety gate is applied.');
$wakeWindow=substr($daemon,max(0,$wakePos-450),1100);
foreach(['gmail','sp_api','financial','scheduler','seller_central'] as $task){
    kdiAssert(str_contains($wakeWindow,"'{$task}'"),'Known-date wake must request '.$task.' immediately.');
}

$rules=(string)file_get_contents(__DIR__.'/../docs/REGRAS-DE-ENTREGA.md');
kdiAssert(
    str_contains($rules,'teste funcional de ponta a ponta') && str_contains($rules,'não pode ser considerada concluída'),
    'Delivery rules must explicitly forbid completion without real end-to-end functional validation.'
);

echo "known-deadline-immediate-delivery-test: OK\n";
