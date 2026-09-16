<?php
declare(strict_types=1);
function crhAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$repo=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/CaseRepository.php');
$start=strpos($repo,'$creditMismatch=');
$end=$start===false?false:strpos($repo,';',$start);
crhAssert($start!==false&&$end!==false,'Credit reconciliation health gate must remain explicit.');
$gate=substr($repo,(int)$start,(int)$end-(int)$start);
crhAssert(str_contains($gate,'c.reconciled_credit_amount>0'),'Zero-value cases must not be reported as identified credit awaiting reconciliation.');
crhAssert(str_contains($gate,'$exposure<=0'),'Only fully covered exposure may be flagged as a state reconciliation mismatch.');
echo "credit-reconciliation-health-gate-test: OK\n";
