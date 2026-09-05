<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SpApi.php';
require_once __DIR__.'/../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__.'/../workers/amazon-returns/reconcile.php';
$errors=[];
function fosEq(mixed $wanted,mixed $got,string $label):void{global $errors;if($wanted!==$got)$errors[]=$label.' expected='.json_encode($wanted).' actual='.json_encode($got);}
$worker=new SvAmazonReturnsReconcileWorker();
$case=['expected_reimbursement_amount'=>'100.00','state'=>'SAFE_T_APPROVED','marketplace_id'=>'A2Q3Y263D00KWC'];
$tx=['transaction_id'=>'same-credit','transaction_type'=>'SAFE_T_REIMBURSEMENT','transaction_status'=>'RELEASED','posted_at'=>'2026-09-01T00:00:00Z','total_amount'=>['amount'=>'60.00','currency'=>'BRL']];
$v0=['event_type'=>'SAFE_T_REIMBURSEMENT_OBSERVED','created_at'=>'2026-09-05 01:00:00','id'=>1,'payload'=>['safe_t_claim_id'=>'98143-99485-9285859','posted_at'=>'2026-09-01T00:00:00Z','reimbursed_amount'=>['amount'=>'60.00','currency'=>'BRL']]];
$ledger=['event_type'=>'FINANCIAL_TRANSACTION_OBSERVED','created_at'=>'2026-09-05 01:00:01','id'=>2,'payload'=>['transaction'=>$tx]];
$reconciled=$worker->reconcileCase($case,$worker->transactionsFromEvents([$v0,$ledger]));
fosEq('60.00',$reconciled['credit_amount'],'v0/v2024 corroboration is not two payments');
fosEq('CREDIT_PENDING',$reconciled['state'],'Overlapping partial credits cannot close case');
$old=$ledger;$old['created_at']='2026-09-05 01:00:00';$old['id']=10;$old['payload']['transaction']['total_amount']['amount']='100.00';
$new=$old;$new['created_at']='2026-09-05 02:00:00';$new['id']=11;$new['payload']['transaction']['transaction_status']='DEFERRED';
$selected=$worker->transactionsFromEvents([$new,$old]);
fosEq(1,count($selected),'Latest observation selects one version per transaction');
fosEq('DEFERRED',$selected[0]['transaction_status']??null,'Observation order, not event posting date, decides latest version');
fosEq('0.00',$worker->reconcileCase($case,$selected)['credit_amount'],'Latest deferred observation cannot reuse stale release');
fosEq(true,method_exists(SvAmazonSpApiEventSink::class,'financialObservationKey'),'Versioned financial observation identity exists');
if(method_exists(SvAmazonSpApiEventSink::class,'financialObservationKey')){
    fosEq(false,SvAmazonSpApiEventSink::financialObservationKey(1,$old['payload']['transaction'])===SvAmazonSpApiEventSink::financialObservationKey(1,$new['payload']['transaction']),'Changed status produces a new append-only observation');
    fosEq(true,SvAmazonSpApiEventSink::financialObservationKey(1,$tx)===SvAmazonSpApiEventSink::financialObservationKey(1,$tx),'Unchanged observation stays idempotent');
}
final class FinancialIdentityClient {
    public function marketplaceId():string{return 'A2Q3Y263D00KWC';}
    public function request(string $method,string $path,array $query=[]):array{
        return ['status'=>200,'request_id'=>'request-fixture','data'=>['payload'=>['transactions'=>[[
            'transactionId'=>'related-credit','transactionType'=>'SAFE_T_REIMBURSEMENT','transactionStatus'=>'RELEASED','totalAmount'=>['currencyAmount'=>'100.00','currencyCode'=>'BRL'],
            'relatedIdentifiers'=>[['name'=>'ORDER_ID','value'=>'702-1111111-2222222'],['name'=>'SAFE_T_CLAIM_ID','value'=>'98143-99485-9285859']],
        ]]]]];
    }
}
$data=(new SvAmazonReturnsSpApi(new FinancialIdentityClient()))->listTransactions('702-1111111-2222222');
fosEq(2,count($data['transactions'][0]['related_identifiers']??[]),'Official related identifiers must survive normalization');
if($errors!==[]){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}
echo "financial-observation-safety-test: OK\n";
