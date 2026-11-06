<?php
declare(strict_types=1);

function priorityAssert(bool $ok,string $reason):void {
    if (!$ok) throw new RuntimeException($reason);
}
$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
$begin=strpos($source,'public function pull(DateTimeImmutable $now)');
$end=strpos($source,'public function acceptResult(', $begin===false?0:$begin);
priorityAssert($begin!==false && $end!==false && $end>$begin,
    'Read bridge pull must exist before result handling.');
$pull=substr($source,$begin,$end-$begin);
$knownRead="claimBatch(1,['SAFE_T_READ','SELLER_SUPPORT_READ'])";
$discovery="claimBatch(1,['SAFE_T_DISCOVERY'])";
$readAt=strpos($pull,$knownRead);
$discoveryAt=strpos($pull,$discovery);
priorityAssert($readAt!==false,
    'Known claim and existing support reads must claim ahead of unresolved SAFE-T discovery.');
priorityAssert($discoveryAt!==false && $readAt<$discoveryAt,
    'Unverified discovery must still be claimable after urgent reads are exhausted.');
$between=substr($pull,$readAt,$discoveryAt-$readAt);
priorityAssert(str_contains($between,'if($rows===[])'),
    'Discovery must only be claimed when no known-claim/support read was claimed.');
priorityAssert(strpos($pull, 'if($rows===[])return [\'status\'=>\'NO_JOB\'')>$discoveryAt,
    'NO_JOB must only be returned after both read lanes are checked.');
foreach(['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'] as $write){
    priorityAssert(!str_contains($pull,$write), 'Read-only pull must never dispatch write type '.$write);
}
echo "safe-t-claim-read-priority-test: OK\n";
