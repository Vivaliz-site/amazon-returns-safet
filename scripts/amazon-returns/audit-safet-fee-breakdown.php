<?php
declare(strict_types=1);
/** Read-only SP-API v0 + v2024 SAFE-T fee audit. No writes. */
require_once __DIR__.'/../../includes/Database.php';
require_once __DIR__.'/../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__.'/../../includes/amazon-returns/SpApi.php';
require_once __DIR__.'/../../includes/amazon-returns/FinancialReconciler.php';
require_once __DIR__.'/../../includes/amazon-returns/SafeTFeeAudit.php';
$options=getopt('',['tenant-id:','connection-id:','case-ids:']);
$tenant=filter_var($options['tenant-id']??null,FILTER_VALIDATE_INT);
$connection=filter_var($options['connection-id']??null,FILTER_VALIDATE_INT);
$ids=explode(',',trim((string)($options['case-ids']??'')));
if(!is_int($tenant)||$tenant<1||!is_int($connection)||$connection<1 || count($ids)>30) {
    fwrite(STDERR,"Specify tenant-id, connection-id and 1-30 case-ids.\n");exit(2);
}
$ids=array_values(array_unique($ids));
foreach($ids as $id)if(preg_match('/^[1-9][0-9]*$/D',$id)!==1){fwrite(STDERR,"Invalid case ID\n");exit(2);}
$db=amazon_returns_require_pdo();
$persistence=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext($tenant,$connection));
$api=new SvAmazonReturnsSpApi();
$raw=new SvAmazonSpApiClient();
$recon=new SvAmazonFinancialReconciler();
$summary=['requested'=>count($ids),'audited'=>0,'incomplete'=>0,
    'debit_cents'=>0,'credit_cents'=>0,'gap_cents'=>0,
    'proven_fee_cents'=>0,'unitemized_cents'=>0];
foreach($ids as $id){
    try{
        $case=$persistence->cases->find((int)$id);
        if(!is_array($case))throw new RuntimeException('CASE_NOT_FOUND_IN_SCOPE');
        $v2024=$api->listTransactions((string)$case['amazon_order_id']);
        if(($v2024['financial_truth']??false)!==true)throw new RuntimeException('LEDGER_NOT_AUTHORITATIVE');
        $tx=array_map(static function(array $t):array{$t['source']='SP_API_FINANCES_V2024';return $t;},
            $v2024['transactions']??[]);
        $finance=$recon->reconcile($case,$tx);
        $v0=$raw->request('GET','/finances/v0/orders/'.rawurlencode((string)$case['amazon_order_id']).'/financialEvents',[]);
        if((int)($v0['status']??0)!==200)throw new RuntimeException('FINANCE_V0_READ_NOT_OK');
        $events=$v0['data']['payload']['FinancialEvents']??null;
        if(!is_array($events))throw new RuntimeException('FINANCE_V0_EVENTS_MISSING');
        $result=SvAmazonSafeTFeeAudit::analyze($case,$events,(string)$finance['credit_amount']);
        $result['finance_sources']=['SP_API_FINANCES_V2024','SP_API_FINANCES_V0'];
        $result['ledger_matches_stored']=(string)$finance['credit_amount']===(string)$case['reconciled_credit_amount'];
        $result['ledger_unsettled']=$finance['unsettled_financial_evidence']??null;
        $result['ledger_ambiguous']=$finance['ambiguous_reimbursement_transactions']??null;
        $result['read_request_count']=count($v2024['request_ids']??[])+1;
        if(($result['status']??null)==='FEE_COMPONENTS_RECONCILED_EXTRA_UNITEMIZED'
            && $result['ledger_matches_stored']===true
            && $result['ledger_unsettled']===false
            && (int)$result['ledger_ambiguous']===0){
            $summary['audited']++;
            foreach([
                'seller_debit_expected'=>'debit_cents','seller_credit_released'=>'credit_cents',
                'apparent_shortfall_vs_refund_debit'=>'gap_cents',
                'retained_fee_difference'=>'proven_fee_cents',
                'unitemized_taxes_or_other_adjustments'=>'unitemized_cents',
            ] as $field=>$key)$summary[$key]+=(int)round(((float)$result[$field])*100);
        }else{$summary['incomplete']++;}
        echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    }catch(Throwable $e){
        $summary['incomplete']++;
        echo json_encode(['case_id'=>(int)$id,'status'=>'READ_FAILED','error_class'=>$e::class],JSON_THROW_ON_ERROR)."\n";
    }
    usleep(750000);
}
$out=['summary'=>['requested'=>$summary['requested'],'audited'=>$summary['audited'],'incomplete'=>$summary['incomplete']]];
foreach(['debit_cents','credit_cents','gap_cents','proven_fee_cents','unitemized_cents'] as $k){
    $out['summary'][str_replace('_cents','',$k)]=number_format($summary[$k]/100,2,'.','');
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
if($summary['incomplete']>0)exit(1);
