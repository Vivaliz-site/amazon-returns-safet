<?php
declare(strict_types=1);
function unknownAssert(bool $ok,string $msg):void{
    if(!$ok)throw new RuntimeException($msg);
}
$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
$start=strpos($source,'public function acceptResult(');
$end=strpos($source,'private function supportCaseIdForRow(', $start===false?0:$start);
unknownAssert($start!==false&&$end!==false,'Status bridge result handler must be present.');
$handler=substr($source,$start,$end-$start);
$accepted=strpos($handler,<<<'SOURCE'
if($status==='ACCEPTED' && is_array($result['read'] ?? null))
SOURCE
);
$unknown=strpos($handler,<<<'SOURCE'
if($kind==='SAFE_T_READ' && $status==='ACCEPTED'
SOURCE
);
unknownAssert($unknown!==false&&$accepted!==false&&$unknown<$accepted,
    'Unknown SAFE-T status must not be acknowledged as a completed claim read.');
$branch=substr($handler,$unknown,$accepted-$unknown);
foreach(['claim_status',"'UNKNOWN'",'SAFE_T_STATUS_UNRECOGNIZED','->defer(',"'completed'=>false"] as $key){
    unknownAssert(str_contains($branch,$key),'Unknown status must be durably classified for retry: '.$key);
}
unknownAssert(!str_contains($branch,'markSucceeded('),
    'An unreadable claim state must not be marked successfully reconciled.');
$outbox=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/TenantOutbox.php');
unknownAssert(str_contains($outbox,"'SAFE_T_STATUS_UNRECOGNIZED'"),
    'UI drift reason must be enumerated in operational health diagnostics.');
echo "safe-t-unknown-readback-failclosed-test: OK\n";
