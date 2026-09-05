<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
$engine=new SvAmazonSafeTDecisionEngine();$now=new DateTimeImmutable('2026-09-05T15:00:00Z');
$base=['id'=>77,'safe_t_id'=>'11111-22222-3333333','state'=>'SAFE_T_DENIED','physical_status'=>'NOT_RECEIVED','latest_denial_text'=>'Negamos a reivindicacao','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00'];
$errors=[];
foreach(['SAFE_T_DENIED','APPEAL_REQUIRED','SAFE_T_INFO_REQUESTED'] as $state){
 $case=$base;$case['state']=$state;
 foreach([null,'2026-09-02 18:00:00','2026-02-31 18:00:00'] as $deadline){$case['appeal_deadline_at']=$deadline;$action=$engine->nextAction($case,[],['eligible'=>true],$now)['action'];if($action!=='HUMAN_REVIEW')$errors[]=$state.' invalid/expired deadline must not send '.$action;}
 $case['appeal_deadline_at']='2026-09-08 18:00:00';if($engine->nextAction($case,[],['eligible'=>true],$now)['action']!=='SAFE_T_APPEAL')$errors[]='Valid official window still permits the appeal decision';
}
$clocked=new SvAmazonSafeTDecisionEngine(null,new DateTimeImmutable('2026-10-01T00:00:00Z'));$case=$base;$case['appeal_deadline_at']='2026-09-08 18:00:00';if($clocked->nextAction($case,[],['eligible'=>true])['action']!=='HUMAN_REVIEW')$errors[]='Configured clock must control deadline decisions deterministically';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "appeal-deadline-enforcement-test: OK\n";
