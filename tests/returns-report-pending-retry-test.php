<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/Runtime.php';

function rrAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$at=new DateTimeImmutable('2026-09-18T02:00:00Z');
$state=['returns_report'=>$at->format(DATE_ATOM)];

rrAssert(
    !in_array('returns_report',SvAmazonReturnsRuntime::dueTasks($state,$at->modify('+299 seconds'),null,null,null,null,null,true),true),
    'A pending report must not hot-loop before five minutes.'
);
rrAssert(
    in_array('returns_report',SvAmazonReturnsRuntime::dueTasks($state,$at->modify('+300 seconds'),null,null,null,null,null,true),true),
    'A persisted pending report must be due again after five minutes.'
);
rrAssert(
    !in_array('returns_report',SvAmazonReturnsRuntime::dueTasks($state,$at->modify('+300 seconds'),null,null,null,null,null,false),true),
    'Without a pending cursor, Returns Reports must retain the normal 12-hour cadence.'
);
rrAssert(
    in_array('returns_report',SvAmazonReturnsRuntime::dueTasks(['returns_report'=>'2026-09-17T21:56:43Z'],$at->modify('+35 minutes'),null,null,null,null,null,true),true),
    'A pending report that predates deployment must wake immediately under the short pending cadence.'
);

$daemon=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');
rrAssert(str_contains($daemon,"loadCursor(\$this->persistence,'pending_report')!==null"),'Daemon must derive short cadence from the persisted pending-report cursor.');
rrAssert(str_contains($daemon,'$erpSalesReturnStackRevision,$returnsReportPending'),'Daemon must pass pending-report state to dueTasks.');
echo "returns-report-pending-retry-test: OK\n";
