<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/FinancialReconciler.php';

$errors=[];
function frtSame(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
function frtTx(string $id,string $amount,string $status='RELEASED'):array{return ['source'=>'SP_API_FINANCES_V2024','transaction_id'=>$id,'transaction_type'=>'SAFE_T_REIMBURSEMENT','transaction_status'=>$status,'posted_at'=>'2026-09-07T20:00:00Z','total_amount'=>['amount'=>$amount,'currency'=>'BRL']];}

$reconciler=new SvAmazonFinancialReconciler();
$case=['quantity_ordered'=>1,'quantity_refunded'=>1,'expected_reimbursement_amount'=>'100.00','state'=>'CREDIT_PENDING','marketplace_id'=>'A2Q3Y263D00KWC'];

$under=$reconciler->reconcile($case,[frtTx('under-five','96.00')]);
frtSame('RECOVERED',$under['state']??null,'A released credit leaving a residual below 5 percent must be treated as settled.');
frtSame('0.00',$under['outstanding_amount']??null,'A tolerated residual must not drive another recovery claim.');
frtSame(true,$under['residual_tolerance_applied']??null,'Sub-5-percent settlement must be auditable.');
frtSame('4.00',$under['tolerated_residual_amount']??null,'The tolerated residual amount must be retained for audit.');

$multi=$case;
$multi['quantity_ordered']=2;
$multi['quantity_refunded']=2;
$multi['expected_reimbursement_amount']='200.00';
$multiUnder=$reconciler->reconcile($multi,[frtTx('multi-under-five','192.50')]);
frtSame('RECOVERED',$multiUnder['state']??null,'The approved percentage tolerance applies to a verified multi-unit reimbursement too.');
frtSame('0.00',$multiUnder['outstanding_amount']??null,'A verified multi-unit residual below 5 percent must not create another claim.');
frtSame(true,$multiUnder['residual_tolerance_applied']??null,'Multi-unit tolerance must remain auditable.');

$boundary=$reconciler->reconcile($case,[frtTx('exact-five','95.00')]);
frtSame('CREDIT_PENDING',$boundary['state']??null,'Exactly 5 percent residual must remain pending.');
frtSame('5.00',$boundary['outstanding_amount']??null,'Exactly 5 percent residual must remain recoverable.');
frtSame(false,$boundary['residual_tolerance_applied']??null,'The 5 percent boundary must not be tolerated.');

$unreleased=$reconciler->reconcile($case,[frtTx('not-released','99.00','DEFERRED')]);
frtSame('CREDIT_PENDING',$unreleased['state']??null,'An unreleased transaction must never trigger tolerance.');
frtSame(false,$unreleased['residual_tolerance_applied']??null,'Tolerance requires a real released seller credit.');

if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "financial-residual-tolerance-test: OK\n";
