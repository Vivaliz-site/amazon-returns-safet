<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SpApi.php';
require_once __DIR__.'/../includes/amazon-returns/FinancialReconciler.php';

final class SerracFakeClient {
    public function marketplaceId(): string { return 'A2Q3Y263D00KWC'; }
    public function request(string $method,string $path,array $query=[],?array $body=null): array {
        return ['status'=>200,'request_id'=>'req-serrac','data'=>['transactions'=>[[
            'transactionId'=>'adj-serrac-1','transactionType'=>'Adjustment','transactionStatus'=>'RELEASED',
            'description'=>'SERRACReimbursement','postedDate'=>'2026-05-06T00:04:03Z',
            'totalAmount'=>['currencyAmount'=>111.25,'currencyCode'=>'BRL'],
            'relatedIdentifiers'=>[['relatedIdentifierName'=>'ORDER_ID','relatedIdentifierValue'=>'702-0707321-6872209']],
            'breakdowns'=>[['breakdownType'=>'Reimbursements','breakdownAmount'=>['currencyAmount'=>111.25,'currencyCode'=>'BRL']]],
        ]]]];
    }
}

$api=new SvAmazonReturnsSpApi(new SerracFakeClient());
$transactions=$api->listTransactions('702-0707321-6872209')['transactions'];
$tx=$transactions[0]??[];$errors=[];
function srSame(mixed $want,mixed $got,string $why):void{global $errors;if($want!==$got)$errors[]=$why.' expected='.json_encode($want).' actual='.json_encode($got);}
srSame('SERRACReimbursement',$tx['description']??null,'Finances normalization must retain reimbursement description.');
srSame('Reimbursements',$tx['breakdowns'][0]['breakdown_type']??null,'Finances normalization must retain reimbursement breakdown type.');
$case=['expected_reimbursement_amount'=>'112.50','state'=>'POLICY_REVIEW_REQUIRED','marketplace_id'=>'A2Q3Y263D00KWC'];
$result=(new SvAmazonFinancialReconciler())->reconcile($case,$transactions);
srSame('111.25',$result['credit_amount'],'Released SERRAC reimbursement must count as seller credit.');
srSame('1.25',$result['outstanding_amount'],'Only the residual may remain after the explicit Amazon reimbursement.');
srSame(0,$result['unclassified_transactions'],'Explicit reimbursement adjustment must not remain unclassified.');
$generic=$tx;$generic['transaction_id']='adj-generic';$generic['description']='OtherAdjustment';$generic['breakdowns']=[];
$result=(new SvAmazonFinancialReconciler())->reconcile($case,[$generic]);
srSame('0.00',$result['credit_amount'],'Generic Adjustment must never be invented as reimbursement.');
srSame(1,$result['unclassified_transactions'],'Generic Adjustment must remain unclassified.');
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "serrac-reimbursement-reconciliation-test: OK\n";
