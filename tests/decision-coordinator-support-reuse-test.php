<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/DecisionCoordinator.php';

function dcsrSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m.' expected='.var_export($e,true).' actual='.var_export($a,true));}
function dcsrAssert(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}

dcsrAssert(method_exists(SvAmazonDecisionCoordinator::class,'enforceSupportCaseReuse'),'Coordinator must expose the support-case reuse invariant.');

if(method_exists(SvAmazonDecisionCoordinator::class,'enforceSupportCaseReuse')){
    $case=['id'=>13232,'support_case_id'=>'22144820051'];
    $open=['action'=>'SELLER_SUPPORT_OPEN','reason'=>'CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION','idempotency_key'=>str_repeat('a',64),'support_route'=>'GENERAL_ORDER_SUPPORT'];
    $updated=SvAmazonDecisionCoordinator::enforceSupportCaseReuse($open,$case);
    dcsrSame('SELLER_SUPPORT_UPDATE',$updated['action']??null,'A bound support case must be updated, never opened again.');
    dcsrSame('22144820051',$updated['support_case_id']??null,'Reuse must preserve the exact bound Seller Support case ID.');
    dcsrAssert(($updated['idempotency_key']??'')!==$open['idempotency_key'],'OPEN and UPDATE writes must never share an idempotency key.');

    $unbound=SvAmazonDecisionCoordinator::enforceSupportCaseReuse($open,['id'=>13246,'support_case_id'=>null]);
    dcsrSame('SELLER_SUPPORT_OPEN',$unbound['action']??null,'An unbound case may still open Seller Support.');

    $wait=SvAmazonDecisionCoordinator::enforceSupportCaseReuse(['action'=>'WAIT','reason'=>'ACTIVE'], $case);
    dcsrSame('WAIT',$wait['action']??null,'The invariant must not rewrite non-support-open decisions.');
}

echo "decision-coordinator-support-reuse-test: OK\n";
