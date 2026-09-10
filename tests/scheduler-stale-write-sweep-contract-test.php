<?php
declare(strict_types=1);
function sswAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$daemon=(string)file_get_contents(dirname(__DIR__).'/workers/amazon-returns/daemon.php');
sswAssert(str_contains($daemon,'pendingWriteCaseIds()'),'Scheduler must include terminal cases that still own pending external writes.');
sswAssert(str_contains($daemon,'supersedePendingWritesExcept('),'Scheduler must sweep stale pending writes before enqueueing current intent.');
sswAssert(str_contains($daemon,'normalizeRecoveryChannel('),'Sweep must compare queued jobs against the effective normalized recovery action.');
sswAssert(str_contains($daemon,"'superseded_writes'=>"),'Scheduler audit must report how many stale writes were neutralized.');
sswAssert(str_contains($daemon,'SUPERSEDED_BY_CURRENT_DECISION'),'Superseded writes must record the current decision reason.');
echo "scheduler-stale-write-sweep-contract-test: OK\n";
