<?php
declare(strict_types=1);
require_once __DIR__ . '/Enums.php';

final class SvAmazonFinancialReconciler
{
    /** @return array<string,mixed> */
    public function reconcile(array $case,array $transactions): array
    {
        $expected=max(0,self::cents($case['expected_reimbursement_amount']??0)??0);
        $currency=strtoupper(trim((string)($case['currency']??$case['expected_currency']??'')));
        if($currency==='' && ($case['marketplace_id']??'')==='A2Q3Y263D00KWC')$currency='BRL';
        $latest=[];$anonymous=[];
        foreach($transactions as $tx){
            if(!is_array($tx))continue;
            $id=trim((string)($tx['transaction_id']??''));
            if($id!=='')$latest[$id]=$tx;
            else $anonymous[hash('sha256',json_encode($tx,JSON_THROW_ON_ERROR))]=$tx;
        }
        $ids=[];$unclassified=0;$groups=[];$positives=['v0'=>0,'ledger'=>0];$debits=0;
        foreach(array_merge(array_values($latest),array_values($anonymous)) as $tx){
            $effect=$this->sellerEffect($tx);
            $money=is_array($tx['total_amount']??null)?$tx['total_amount']:[];
            $txCurrency=strtoupper(trim((string)($money['currency']??'')));
            if($effect===null || ($currency!=='' && $txCurrency!=='' && $currency!==$txCurrency)){$unclassified++;continue;}
            $id=trim((string)($tx['transaction_id']??''));
            if($id!=='')$ids[]=$id;
            $source=($tx['source']??'')==='SP_API_FINANCES_V0'?'v0':'ledger';
            $status=strtoupper(trim((string)($tx['transaction_status']??'')));
            $type=strtoupper(trim((string)($tx['transaction_type']??'')));
            if($type!=='' && in_array($status,['RELEASED','DEFERRED_RELEASED'],true)){
                $related=$tx['related_identifiers']??$tx['relatedIdentifiers']??[];
                $relatedKey=hash('sha256',json_encode($related,JSON_THROW_ON_ERROR));
                $key=implode('|',[$source,$type,(string)$effect,$txCurrency,$relatedKey]);
                if(!isset($groups[$key]))$groups[$key]=['effect'=>$effect,'source'=>$source,'statuses'=>[]];
                $groups[$key]['statuses'][$status]=(int)($groups[$key]['statuses'][$status]??0)+1;
            }elseif($effect<0){$debits+=$effect;}else{$positives[$source]+=$effect;}
        }
        foreach($groups as $group){
            // Two lifecycle representations must not double the same economic event.
            $count=max((int)($group['statuses']['RELEASED']??0),(int)($group['statuses']['DEFERRED_RELEASED']??0));
            $effect=$group['effect']*$count;
            if($effect<0)$debits+=$effect;else $positives[$group['source']]+=$effect;
        }
        // Finances v0 and v2024 can describe the same reimbursement. Use independent
        // corroborating ledgers, never add them together as separate payments.
        $credit=max(0,max($positives['v0'],$positives['ledger'])+$debits);
        $outstanding=max(0,$expected-$credit);
        $previous=(string)($case['state']??SvAmazonReturnStates::AWAITING_RETURN);
        $reopened=$previous===SvAmazonReturnStates::RECOVERED && $outstanding>0;
        $state=$previous;
        if($expected>0 && $outstanding===0)$state=SvAmazonReturnStates::RECOVERED;
        elseif(in_array($previous,[SvAmazonReturnStates::SAFE_T_APPROVED,SvAmazonReturnStates::APPEAL_APPROVED,SvAmazonReturnStates::CREDIT_PENDING,SvAmazonReturnStates::RECOVERED],true))$state=SvAmazonReturnStates::CREDIT_PENDING;
        return [
            'state'=>$state,'credit_amount'=>self::money($credit),
            'outstanding_amount'=>self::money($outstanding),'reopened'=>$reopened,
            'transaction_ids'=>array_values(array_unique($ids)),
            'unclassified_transactions'=>$unclassified,
            'corroborating_sources'=>$positives['v0']>0 && $positives['ledger']>0,
        ];
    }

    private function sellerEffect(array $tx): ?int
    {
        $status=strtoupper(trim((string)($tx['transaction_status']??'')));
        if($status!=='' && !in_array($status,['RELEASED','DEFERRED_RELEASED'],true))return null;
        if(isset($tx['seller_effect_amount']))return self::cents($tx['seller_effect_amount']);
        if($status==='' || trim((string)($tx['transaction_id']??''))==='')return null;
        $type=strtoupper(trim((string)($tx['transaction_type']??'')));
        if(!str_contains($type,'REIMBURSE') && !str_contains($type,'COMPENSATION') && !str_contains($type,'SAFE_T'))return null;
        $money=is_array($tx['total_amount']??null)?$tx['total_amount']:[];
        if(preg_match('/^[A-Z]{3}$/',strtoupper((string)($money['currency']??'')))!==1)return null;
        $amount=self::cents($money['amount']??null);
        if($amount===null)return null;
        return str_contains($type,'REVERSAL')?-abs($amount):$amount;
    }

    private static function cents(mixed $value): ?int
    {
        if(!is_string($value) && !is_int($value) && !is_float($value))return null;
        $text=trim((string)$value);
        if(preg_match('/^(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?$/D',$text,$m)!==1)return null;
        $minor=(int)$m[2]*100+(int)str_pad($m[3]??'',2,'0');
        return $m[1]==='-'?-$minor:$minor;
    }

    private static function money(int $cents): string
    {
        return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
    }
}
