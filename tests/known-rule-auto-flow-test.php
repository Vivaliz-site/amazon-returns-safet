<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__.'/../workers/amazon-returns/scheduler.php';
function kra($v,$m){if(!$v)throw new RuntimeException($m);} function ksame($a,$b,$m){if($a!==$b)throw new RuntimeException($m.' want='.json_encode($a).' got='.json_encode($b));}
$engine=new SvAmazonSafeTDecisionEngine();$now=new DateTimeImmutable('2026-09-06T03:00:00Z');
$case=['id'=>7,'amazon_order_id'=>'702-x','safe_t_id'=>null,'state'=>'REFUND_DETECTED','physical_status'=>'NOT_RECEIVED','refund_at'=>'2026-07-20 00:00:00','refund_initiator'=>'AMAZON_AUTOMATIC','expected_reimbursement_amount'=>'100','reconciled_credit_amount'=>'0'];
$r=$engine->guardLearnedEffect(['action'=>'SAFE_T_SUBMIT','parameters'=>['date_binding'=>'NONE']],$case,[],['eligible'=>false],$now);ksame('HUMAN_REVIEW',$r['action'],'D45 gate cannot bypass');
$dam=$case;$dam['physical_status']='RECEIVED_DISCREPANT';$r=$engine->guardLearnedEffect(['action'=>'SAFE_T_SUBMIT','parameters'=>['date_binding'=>'NONE']],$dam,[],['eligible'=>true],$now);ksame('HUMAN_REVIEW',$r['action'],'damaged initial manual');
$appeal=$case;$appeal['safe_t_id']='12345-12345-1234567';$appeal['state']='APPEAL_SUBMITTED';$appeal['appeal_deadline_at']='2026-09-10 00:00:00';$r=$engine->guardLearnedEffect(['action'=>'SAFE_T_APPEAL','parameters'=>['date_binding'=>'NONE']],$appeal,[],['eligible'=>true],$now);ksame('WAIT',$r['action'],'no duplicate appeal');
$wait=$engine->guardLearnedEffect(['action'=>'WAIT','parameters'=>['date_binding'=>'PROMISED_DATE','resolved_date'=>'2026-09-10 00:00:00']],$appeal,[],['eligible'=>true],$now);ksame('2026-09-10 00:00:00',$wait['next_action_at'],'exact bound wait date');
$src=(string)file_get_contents(__DIR__.'/../workers/amazon-returns/daemon.php');kra(str_contains($src,'SvAmazonDecisionCoordinator'),'daemon uses coordinator');kra(str_contains($src,'scheduleDecision('),'daemon schedules precomputed decision');
echo "known-rule-auto-flow-test: OK\n";
