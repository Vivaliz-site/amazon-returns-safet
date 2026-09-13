<?php
declare(strict_types=1);

require_once __DIR__.'/FinancialObservations.php';

final class SvAmazonCaseConsultation
{
    /** @return array{sales_invoice_number:?string,sales_invoice_source:?string,return_invoice_numbers:list<string>} */
    public static function invoiceFacts(array $events): array
    {
        $salesNumber=null;$salesSource=null;$reportNumber=null;$reportSource=null;$returns=[];
        for($i=count($events)-1;$i>=0;$i--){
            $event=$events[$i]??null;if(!is_array($event))continue;
            $type=strtoupper(trim((string)($event['event_type']??'')));
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            if($type==='SALES_INVOICE_LINKED' && $salesNumber===null){
                $candidate=trim((string)($payload['invoice_number']??$payload['sales_invoice_number']??''));
                if($candidate!==''){$salesNumber=$candidate;$salesSource=trim((string)($event['source']??''))?:null;}
            }
            if($reportNumber===null && in_array($type,['RETURN_REPORT_OBSERVED','RETURNS_REPORT_MATCHED'],true)){
                $candidate=trim((string)($payload['invoice_number']??''));
                if($candidate!==''){$reportNumber=$candidate;$reportSource=trim((string)($event['source']??''))?:null;}
            }
            if($type==='RETURN_INVOICE_LINKED'){
                $candidate=trim((string)($payload['return_invoice_number']??$payload['invoice_number']??''));
                if($candidate!=='' && !in_array($candidate,$returns,true))$returns[]=$candidate;
            }
        }
        if($salesNumber===null){$salesNumber=$reportNumber;$salesSource=$reportSource;}
        return ['sales_invoice_number'=>$salesNumber,'sales_invoice_source'=>$salesSource,'return_invoice_numbers'=>array_reverse($returns)];
    }
    /** @return array{amazon_reimbursement_at:?string,amazon_reimbursement_amount:string} */
    public static function financialFacts(array $case,array $events): array
    {
        $credit=max(0,(float)($case['reconciled_credit_amount']??0));
        if($credit<=0.00001)return ['amazon_reimbursement_at'=>null,'amazon_reimbursement_amount'=>'0.00'];
        $latest=null;
        foreach(SvAmazonFinancialObservations::latestEvents($events) as $event){
            if(strtoupper(trim((string)($event['source']??'')))!=='SP_API_FINANCES')continue;
            $tx=is_array($event['payload']['transaction']??null)?$event['payload']['transaction']:[];
            if(!self::isReleasedReimbursement($tx))continue;
            $money=is_array($tx['total_amount']??null)?$tx['total_amount']:[];
            $amount=(float)($tx['seller_effect_amount']??$money['amount']??0);
            if($amount<=0)continue;
            $at=self::utcSql($tx['posted_at']??($event['occurred_at']??null));
            if($at!==null && ($latest===null || strcmp($at,$latest)>0))$latest=$at;
        }
        return [
            'amazon_reimbursement_at'=>$latest,
            'amazon_reimbursement_amount'=>number_format($credit,2,'.',''),
        ];
    }

    private static function isReleasedReimbursement(array $tx): bool
    {
        $status=strtoupper(trim((string)($tx['transaction_status']??'')));
        if(!in_array($status,['RELEASED','DEFERRED_RELEASED'],true))return false;
        $type=strtoupper(trim((string)($tx['transaction_type']??'')));
        if(str_contains($type,'REIMBURSE')||str_contains($type,'COMPENSATION')||str_contains($type,'SAFE_T'))return true;
        if($type!=='ADJUSTMENT')return false;
        if(str_contains(strtoupper((string)($tx['description']??'')),'REIMBURSEMENT'))return true;        $breakdowns=is_array($tx['breakdowns']??null)?$tx['breakdowns']:[];
        foreach($breakdowns as $breakdown){
            if(!is_array($breakdown))continue;
            $kind=strtoupper(trim((string)($breakdown['breakdown_type']??$breakdown['breakdownType']??'')));
            if($kind==='REIMBURSEMENTS')return true;
        }
        return false;
    }

    private static function utcSql(mixed $value): ?string
    {
        if(!is_string($value)||trim($value)==='')return null;
        $value=trim($value);
        try{$date=new DateTimeImmutable($value,new DateTimeZone('UTC'));}
        catch(Throwable){return null;}
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
