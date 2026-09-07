<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/FinancialReconciler.php';

$errors=[];
function ersSame(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
$reconciler=new SvAmazonFinancialReconciler();
$case=['quantity_ordered'=>1,'quantity_refunded'=>1,'expected_reimbursement_amount'=>'112.50','state'=>'POLICY_REVIEW_REQUIRED','marketplace_id'=>'A2Q3Y263D00KWC'];
$tx=[['source'=>'SP_API_FINANCES','transaction_id'=>'serrac-501','transaction_type'=>'Adjustment','transaction_status'=>'RELEASED','description'=>'SERRACReimbursement','total_amount'=>['amount'=>'111.25','currency'=>'BRL'],'breakdowns'=>[['breakdown_type'=>'Reimbursements','breakdown_amount'=>['amount'=>'111.25','currency'=>'BRL']]]]];
$result=$reconciler->reconcile($case,$tx);
ersSame('RECOVERED',$result['state']??null,'A released explicit Amazon reimbursement for a single refunded unit must settle the recovery claim even when the legacy expected amount is higher.');
ersSame('0.00',$result['outstanding_amount']??null,'A settled explicit reimbursement must not create a false residual claim.');
ersSame(true,$result['explicit_reimbursement_settled']??null,'Settlement must remain auditable.');
ersSame('1.25',$result['legacy_expected_gap_amount']??null,'Legacy expected-vs-credit gap should be retained for amount auditing without driving a duplicate claim.');
$multi=$case;$multi['quantity_ordered']=2;$multi['quantity_refunded']=2;$multi['expected_reimbursement_amount']='225.00';
$multiResult=$reconciler->reconcile($multi,$tx);
ersSame(false,$multiResult['explicit_reimbursement_settled']??null,'A single reimbursement observation must not auto-settle a multi-unit refund.');
ersSame('113.75',$multiResult['outstanding_amount']??null,'Multi-unit cases must remain conservative.');
$reversed=[...$tx,['source'=>'SP_API_FINANCES','transaction_id'=>'serrac-reversal','transaction_type'=>'Adjustment','transaction_status'=>'RELEASED','description'=>'SERRACReimbursementReversal','total_amount'=>['amount'=>'-111.25','currency'=>'BRL'],'breakdowns'=>[['breakdown_type'=>'Reimbursements','breakdown_amount'=>['amount'=>'-111.25','currency'=>'BRL']]]]];
$reversedResult=$reconciler->reconcile($case,$reversed);
ersSame(false,$reversedResult['explicit_reimbursement_settled']??null,'A fully reversed reimbursement must never count as settled.');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "explicit-reimbursement-settlement-test: OK\n";
