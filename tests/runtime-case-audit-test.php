<?php
declare(strict_types=1);
$file=__DIR__.'/../includes/amazon-returns/RuntimeAudit.php';
if(!is_file($file)){fwrite(STDERR,"Missing private case-level runtime audit\n");exit(1);}
require_once $file;
require_once __DIR__.'/../workers/amazon-returns/reconcile.php';
function rcaSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why);}
$case=['id'=>7,'amazon_order_id'=>'702-1111111-2222222','state'=>'SAFE_T_APPROVED','program'=>'DBA','expected_reimbursement_amount'=>'100.00','safe_t_id'=>'11111-22222-3333333','customer_name'=>'PRIVATE_NAME','token'=>'PRIVATE_TOKEN'];
$row=SvAmazonReturnsRuntimeAudit::decision($case,[['event_type'=>'SAFE_T_EMAIL_REVIEW_SENT','payload'=>['body'=>'PRIVATE_BODY']]],['eligible'=>true],['action'=>'WAIT','reason'=>'REVIEW_PENDING','payload'=>['secret'=>'PRIVATE_PAYLOAD']]);
rcaSame(7,$row['case_id'],'decision audit case binding');rcaSame('WAIT',$row['action'],'decision audit action');
rcaSame(1,$row['event_counts']['SAFE_T_EMAIL_REVIEW_SENT'],'history remains auditable without copying messages');
rcaSame(false,str_contains(json_encode($row),'PRIVATE_'),'audit must not leak arbitrary private payloads');
$result=['state'=>'CREDIT_PENDING','credit_amount'=>'0.00','outstanding_amount'=>'100.00','transaction_ids'=>[],'reopened'=>true];
$row=SvAmazonReturnsRuntimeAudit::financial($case,[],$result,true);
rcaSame('CREDIT_PENDING',$row['calculated_state'],'financial state audit');rcaSame(true,$row['applied'],'audit distinguishes proposed and applied state');
rcaSame(false,str_contains(json_encode($row),'PRIVATE_'),'financial audit must not copy arbitrary case fields');
$worker=new SvAmazonReturnsReconcileWorker();
if(!method_exists($worker,'shouldUpdateCase')){fwrite(STDERR,"Missing unsupported recovered-case revalidation\n");exit(1);}
rcaSame(false,$worker->shouldUpdateCase($case,[]),'no evidence should not mutate a normal pending case');
$case['state']='RECOVERED';rcaSame(true,$worker->shouldUpdateCase($case,[]),'recovered without evidence must be revalidated');
rcaSame('CREDIT_PENDING',$worker->reconcileCase($case,[])['state'],'unsupported recovery must reopen');

$stale=$case;$stale['eligibility_at']='2026-09-30 12:00:00';$stale['next_action_at']='2026-09-30 12:00:00';
$row=SvAmazonReturnsRuntimeAudit::decision($stale,[],['eligibility_at'=>'2026-08-31 12:00:00'],['action'=>'HUMAN_REVIEW','next_action_at'=>null]);
rcaSame('2026-08-31 12:00:00',$row['eligibility_at'],'audit must show current calculation rather than stale old policy date');
rcaSame(null,$row['next_action_at'],'explicit unknown requested date must not expose an obsolete date');

echo "runtime-case-audit-test: OK\n";
