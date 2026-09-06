<?php
declare(strict_types=1);
require_once __DIR__.'/ReviewRepository.php';

final class SvAmazonRuleApplicationRepository
{
    use SvAmazonReviewMemorySql;
    private const TABLE='amazon_return_rule_applications';

    public function record(array $application): int
    {
        $values=[
            'application_key'=>self::hash((string)($application['application_key']??'')),
            'rule_id'=>(int)($application['rule_id']??0),'case_id'=>(int)($application['case_id']??0),
            'signature_hash'=>self::hash((string)($application['signature_hash']??'')),
            'effect_hash'=>self::hash((string)($application['effect_hash']??'')),
            'result'=>self::text((string)($application['result']??''),64),
            'blockers_json'=>self::json($application['blockers']??[]),'action_ref'=>$application['action_ref']??null,
        ];
        return $this->atomic(function () use ($values): int {
            $rule=$this->owned('amazon_return_learned_rules',$values['rule_id'],true);
            $this->owned('amazon_return_cases',$values['case_id']);
            $values['rule_version']=(int)$rule['version'];
            $existing=$this->rows(self::TABLE,'application_key=:key',[':key'=>$values['application_key']],'FOR UPDATE');
            if ($existing) {
                foreach ($values as $key=>$value) {
                    $same=$key==='blockers_json' ? self::canonical(json_decode($existing[0][$key],true,512,JSON_THROW_ON_ERROR))===self::canonical(json_decode($value,true,512,JSON_THROW_ON_ERROR)) : (string)$existing[0][$key]===(string)$value;
                    if (!$same) throw new RuntimeException('Application key was reused with different immutable facts.');
                }
                return (int)$existing[0]['id'];
            }
            return $this->insert(self::TABLE,$values+['created_at'=>self::now()]);
        });
    }
    public function forCase(int $caseId): array { return $this->rows(self::TABLE,'case_id=:case_id',[':case_id'=>$caseId]); }
    public function countAll(): int { return (int)$this->sql('SELECT COUNT(*) FROM '.self::TABLE.' WHERE '.$this->scope())->fetchColumn(); }
    public function pendingOutcomes(int $limit=500): array
    {
        if ($limit<1 || $limit>10000) throw new InvalidArgumentException('Invalid outcome batch limit.');
        return $this->rows(self::TABLE,"(outcome IN ('PENDING','APPROVED_PENDING_CREDIT') OR JSON_CONTAINS(COALESCE(counted_outcomes_json,JSON_ARRAY()),JSON_QUOTE(outcome))=0)",[],'ORDER BY id LIMIT '.$limit);
    }
    public function recordOutcome(int $applicationId, string $outcome, array $evidenceRefs): void
    {
        self::outcome($outcome);
        $this->atomic(function () use ($applicationId,$outcome,$evidenceRefs): void {
            $app=$this->owned(self::TABLE,$applicationId,true);
            if ($app['outcome']===$outcome) return;
            if (in_array($app['outcome'],['RECOVERED','DENIED','CLOSED_LOSS'],true)) throw new RuntimeException('Terminal application outcome is immutable.');
            if ($outcome==='PENDING' || ($outcome!=='PENDING' && !$evidenceRefs)) throw new InvalidArgumentException('An outcome requires evidence and cannot regress to pending.');
            $this->change(self::TABLE,$applicationId,['outcome'=>$outcome,'outcome_evidence_refs_json'=>self::json($evidenceRefs),'outcome_at'=>self::now()]);
        });
    }
}
