<?php
declare(strict_types=1);
require_once __DIR__.'/Enums.php';

final class SvAmazonLearnedRuleOutcome
{
    public static function classify(array $case,array $timeline): string
    {
        $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
        $credit=max(0.0,(float)($case['reconciled_credit_amount']??0));
        if($expected>0.0 && $credit+0.005>=$expected)return 'RECOVERED';
        $state=strtoupper(trim((string)($case['state']??'')));
        if($state===SvAmazonReturnStates::CLOSED_LOSS)return 'CLOSED_LOSS';
        if($state===SvAmazonReturnStates::APPEAL_DENIED_FINAL)return 'DENIED';
        if(in_array($state,[SvAmazonReturnStates::SAFE_T_APPROVED,SvAmazonReturnStates::APPEAL_APPROVED,SvAmazonReturnStates::CREDIT_PENDING],true))return 'APPROVED_PENDING_CREDIT';
        return 'PENDING';
    }

    /** @return list<string> */
    public static function evidenceRefs(array $case,array $timeline): array
    {
        $refs=[];
        for($i=count($timeline)-1;$i>=0 && count($refs)<10;$i--){
            $event=$timeline[$i]??null;if(!is_array($event))continue;
            $id=(int)($event['id']??0);if($id>0)$refs[]='event:'.$id;
            $sha=strtolower(trim((string)($event['evidence_sha256']??'')));
            if(preg_match('/^[a-f0-9]{64}$/',$sha)===1)$refs[]='evidence:'.$sha;
        }
        return array_values(array_unique($refs));
    }
}
