<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/amazon-returns/CockpitCaseSummary.php';

function csAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function csSame(mixed $expected,mixed $actual,string $message):void{if($expected!==$actual)throw new RuntimeException($message);}

$now=new DateTimeImmutable('2026-09-10 15:00:00',new DateTimeZone('UTC'));
$base=[
    'state'=>'SAFE_T_DENIED','refund_amount'=>100.0,'reconciled_credit_amount'=>20.0,'outstanding_amount'=>80.0,
    'current_action'=>'SAFE_T_APPEAL','current_reason'=>'SAFE_T_DENIAL_REQUIRES_FIRST_APPEAL',
    'appeal_deadline_at'=>'2026-09-09 12:00:00','next_action_at'=>null,'eligibility_at'=>null,
    'last_external_write'=>null,'last_read_back'=>['occurred_at'=>'2026-09-08 00:00:00'],
];
$summary=SvAmazonCockpitCaseSummary::build($base,null,$now);
csSame('SYSTEM',$summary['responsibility'],'Automatable case belongs to system.');
csSame(true,$summary['overdue'],'Past due automatic appeal must be flagged.');
csSame(true,$summary['stale'],'Old readback must be flagged as stale.');
csAssert(str_contains($summary['next_step'],'recurso'),'Next step must explain appeal in plain language.');

$review=$base;$review['current_action']='HUMAN_REVIEW';
$reviewSummary=SvAmazonCockpitCaseSummary::build($review,['status'=>'OPEN'],$now);
csSame('USER',$reviewSummary['responsibility'],'Open review must be user responsibility.');
csSame(false,$reviewSummary['overdue'],'Human review must not masquerade as automation failure.');

$done=$base;$done['state']='RECOVERED';$done['current_action']='WAIT';$done['outstanding_amount']=0.0;
$doneSummary=SvAmazonCockpitCaseSummary::build($done,null,$now);
csSame('COMPLETE',$doneSummary['responsibility'],'Recovered case must be terminal for operator.');
csSame(false,$doneSummary['overdue'],'Recovered case must not be overdue.');
echo "cockpit-case-summary-test: OK\n";