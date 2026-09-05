<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/reconcile.php';
$errors=[];
function fcsSame(mixed $want,mixed $got,string $label):void{global $errors;if($want!==$got)$errors[]=$label.' expected='.json_encode($want).' actual='.json_encode($got);}
$worker=new SvAmazonReturnsReconcileWorker();
$case=['expected_reimbursement_amount'=>'100.00','state'=>'SAFE_T_APPROVED','marketplace_id'=>'A2Q3Y263D00KWC'];
function fcsTx(string $id,string $amount='100.00',string $status='RELEASED',string $currency='BRL'):array{return ['transaction_id'=>$id,'transaction_type'=>'SAFE_T_REIMBURSEMENT','transaction_status'=>$status,'posted_at'=>'2026-09-01T00:00:00Z','total_amount'=>['amount'=>$amount,'currency'=>$currency]];}
function fcsEvent(array $tx,int $id,string $created):array{return ['event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','id'=>$id,'created_at'=>$created,'payload'=>['transaction'=>$tx]];}
$tx=fcsTx('same-credit','60.00');
$v0=['event_type'=>'SAFE_T_REIMBURSEMENT_OBSERVED','created_at'=>'2026-09-05 01:00:00','id'=>1,'payload'=>['safe_t_claim_id'=>'11111-22222-3333333','posted_at'=>'2026-09-01T00:00:00Z','reimbursed_amount'=>['amount'=>'60.00','currency'=>'BRL']]];
$ledger=fcsEvent($tx,2,'2026-09-05 01:00:01');
$r=$worker->reconcileCase($case,$worker->transactionsFromEvents([$v0,$ledger]));
fcsSame('60.00',$r['credit_amount'],'corroborating APIs must not double a payment');
fcsSame('CREDIT_PENDING',$r['state'],'partial corroborated credit cannot close a case');
$old=fcsEvent(fcsTx('mutable'),10,'2026-09-05 01:00:00');
$new=fcsEvent(fcsTx('mutable','100.00','DEFERRED'),11,'2026-09-05 02:00:00');
$selected=$worker->transactionsFromEvents([$new,$old]);
fcsSame(1,count($selected),'one latest observation per transaction');
fcsSame('DEFERRED',$selected[0]['transaction_status']??null,'observation time rather than array order');
fcsSame('0.00',$worker->reconcileCase($case,$selected)['credit_amount'],'a deferred latest version invalidates stale release');
foreach(['DEFERRED','PENDING','UNKNOWN'] as $status){$r=$worker->reconcileCase($case,[fcsTx('held','100.00',$status)]);fcsSame('0.00',$r['credit_amount'],'unreleased status '.$status);fcsSame('CREDIT_PENDING',$r['state'],'unreleased must not close '.$status);}
fcsSame('0.00',$worker->reconcileCase($case,[fcsTx('foreign','100.00','RELEASED','USD')])['credit_amount'],'foreign currency is not BRL credit');
fcsSame('0.00',$worker->reconcileCase($case,[fcsTx('negative','-100.00')])['credit_amount'],'a negative reimbursement cannot become a positive credit');
fcsSame('60.00',$worker->reconcileCase($case,[$tx,$tx])['credit_amount'],'duplicate official transaction ID counted once');
fcsSame('100.00',$worker->reconcileCase($case,[fcsTx('part-a','60.00'),fcsTx('part-b','40.00')])['credit_amount'],'distinct released installments sum');
fcsSame('RECOVERED',$worker->reconcileCase($case,[fcsTx('full')])['state'],'a full released matching-currency credit can close');
fcsSame('RECOVERED',$worker->reconcileCase($case,[fcsTx('released-later','100.00','DEFERRED_RELEASED')])['state'],'released former deferral can close');
$case['state']='RECOVERED';$r=$worker->reconcileCase($case,$selected);fcsSame(true,$r['reopened'],'loss of released credit reopens');fcsSame('CREDIT_PENDING',$r['state'],'reopened waits for credit');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "financial-credit-safety-test: OK\n";
