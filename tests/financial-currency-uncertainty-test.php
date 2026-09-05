<?php
declare(strict_types=1);
require_once __DIR__.'/../workers/amazon-returns/reconcile.php';
function fcuSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}
$worker=new SvAmazonReturnsReconcileWorker();
$case=['expected_reimbursement_amount'=>'100.00','state'=>'SAFE_T_APPROVED'];
$tx=['transaction_id'=>'released','transaction_type'=>'SAFE_T_REIMBURSEMENT','transaction_status'=>'RELEASED','total_amount'=>['amount'=>'100.00','currency'=>'BRL']];
fcuSame('0.00',$worker->reconcileCase($case,[$tx])['credit_amount'],'unknown case currency must not authorize recovery');
$case['marketplace_id']='OTHER_MARKETPLACE';
fcuSame('0.00',$worker->reconcileCase($case,[$tx])['credit_amount'],'unmapped marketplace must fail closed');
$case['expected_currency']='BRL';
fcuSame('100.00',$worker->reconcileCase($case,[$tx])['credit_amount'],'an explicit expected currency permits matching credit');
$v0=$tx;$v0['transaction_id']='older-v0';$v0['source']='SP_API_FINANCES_V0';
$held=$tx;$held['transaction_id']='current-ledger';$held['transaction_status']='DEFERRED';
$r=$worker->reconcileCase($case,[$v0,$held]);
fcuSame('0.00',$r['credit_amount'],'stale v0 credit must never override a held ledger observation');
fcuSame('CREDIT_PENDING',$r['state'],'held ledger versus stale v0 must remain open');
echo "financial-currency-uncertainty-test: OK\n";
