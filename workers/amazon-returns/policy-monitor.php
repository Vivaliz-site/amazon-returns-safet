<?php

declare(strict_types=1);

final class SvAmazonReturnPolicyMonitor
{
    /** @return array{status:string,candidate:?array,existing_id:?int} */
    public function candidate(array $existingPolicies, array $observation): array
    {
        $required=['policy_key','marketplace_id','program','effective_from','eligibility_days','source_url','source_hash'];
        foreach ($required as $key) if (!isset($observation[$key]) || trim((string)$observation[$key])==='') throw new InvalidArgumentException('Missing policy observation field: '.$key);
        if (preg_match('/^[a-f0-9]{64}$/i',(string)$observation['source_hash'])!==1 && strlen((string)$observation['source_hash'])<3) throw new InvalidArgumentException('Invalid policy source hash.');
        foreach ($existingPolicies as $policy) {
            if (!is_array($policy)) continue;
            if ((string)($policy['policy_key'] ?? '')!==(string)$observation['policy_key'] || (string)($policy['marketplace_id'] ?? '')!==(string)$observation['marketplace_id'] || (string)($policy['program'] ?? '')!==(string)$observation['program']) continue;
            $sameDate=(string)($policy['effective_from'] ?? '')===(string)$observation['effective_from'];
            if ($sameDate && (string)($policy['source_hash'] ?? '')===(string)$observation['source_hash'] && (int)($policy['eligibility_days'] ?? 0)===(int)$observation['eligibility_days']) return ['status'=>'UNCHANGED','candidate'=>null,'existing_id'=>(int)($policy['id'] ?? 0)];
            if ($sameDate) return ['status'=>'SOURCE_CHANGED_REVIEW_REQUIRED','candidate'=>$this->normalize($observation,'CANDIDATE'), 'existing_id'=>(int)($policy['id'] ?? 0)];
        }
        return ['status'=>'NEW_VERSION_CANDIDATE','candidate'=>$this->normalize($observation,'CANDIDATE'),'existing_id'=>null];
    }

    public function persistCandidate(PDO $db, array $decision): ?int
    {
        if (($decision['status'] ?? '')!=='NEW_VERSION_CANDIDATE' || !is_array($decision['candidate'] ?? null)) return null;
        $c=$decision['candidate'];
        $stmt=$db->prepare('INSERT INTO amazon_return_policies (policy_key,marketplace_id,program,effective_from,effective_to,eligibility_days,basis,source_url,source_hash,status,created_at) VALUES (:policy_key,:marketplace_id,:program,:effective_from,:effective_to,:eligibility_days,:basis,:source_url,:source_hash,\'CANDIDATE\',UTC_TIMESTAMP())');
        $stmt->execute([':policy_key'=>$c['policy_key'],':marketplace_id'=>$c['marketplace_id'],':program'=>$c['program'],':effective_from'=>$c['effective_from'],':effective_to'=>$c['effective_to'],':eligibility_days'=>$c['eligibility_days'],':basis'=>$c['basis'],':source_url'=>$c['source_url'],':source_hash'=>$c['source_hash']]);
        return (int)$db->lastInsertId();
    }

    private function normalize(array $o, string $status): array
    {
        return ['policy_key'=>trim((string)$o['policy_key']),'marketplace_id'=>trim((string)$o['marketplace_id']),'program'=>trim((string)$o['program']),'effective_from'=>trim((string)$o['effective_from']),'effective_to'=>isset($o['effective_to'])&&$o['effective_to']!==''?(string)$o['effective_to']:null,'eligibility_days'=>(int)$o['eligibility_days'],'basis'=>trim((string)($o['basis'] ?? 'SELLER_DEBIT_AT')),'source_url'=>trim((string)$o['source_url']),'source_hash'=>strtolower(trim((string)$o['source_hash'])),'status'=>$status];
    }
}
