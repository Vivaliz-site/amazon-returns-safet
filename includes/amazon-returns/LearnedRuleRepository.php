<?php
declare(strict_types=1);
require_once __DIR__.'/ReviewRepository.php';

final class SvAmazonLearnedRuleRepository
{
    use SvAmazonReviewMemorySql;
    private const TABLE='amazon_return_learned_rules';

    public function active(): array { return $this->list(['status'=>'ACTIVE']); }
    public function find(int $ruleId): ?array { return $this->rows(self::TABLE,'id=:id',[':id'=>$ruleId],'')[0]??null; }
    public function list(array $filters=[]): array
    {
        $where='1=1'; $params=[];
        foreach (['status','rule_family_key','source_review_id'] as $key) if (isset($filters[$key])) { $where.=" AND {$key}=:{$key}"; $params[':'.$key]=$filters[$key]; }
        return $this->rows(self::TABLE,$where,$params);
    }
    /** Revision excludes mutable telemetry and changes only when executable memory changes. */
    public function revision(): string
    {
        $rows=$this->sql('SELECT id,rule_family_key,version,status FROM '.self::TABLE.' WHERE '.$this->scope().' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        return hash('sha256',$this->context->scopeKey().'|'.self::json($rows));
    }
    public function promote(array $definition): array
    {
        $family=self::text((string)($definition['rule_family_key']??''),191);
        if (!is_array($definition['match']??null) || !is_array($definition['effect']??null)) throw new InvalidArgumentException('Rule match and effect are required.');
        $specificity=filter_var($definition['specificity']??0,FILTER_VALIDATE_INT);
        if ($specificity===false || $specificity<0) throw new InvalidArgumentException('Invalid specificity.');
        return $this->atomic(function () use ($definition,$family,$specificity): array {
            // Lock an existing ownership row, including for the first version of a family.
            $connection=$this->db->prepare('SELECT id FROM amazon_return_connections WHERE tenant_id=:tenant_id AND id=:connection_id FOR UPDATE');
            if (!$connection || !$connection->execute([':tenant_id'=>$this->context->tenantId(),':connection_id'=>$this->context->amazonConnectionId()]) || !$connection->fetchColumn()) throw new RuntimeException('Connection ownership mismatch.');
            $source=$this->owned('amazon_return_reviews',(int)($definition['source_review_id']??0),true);
            if ($source['status']!=='DECIDED' || $source['decision_mode']==='EXCEPTION') throw new RuntimeException('A reusable human decision is required.');
            if ($source['resulting_rule_id']!==null) {
                $existing=$this->owned(self::TABLE,(int)$source['resulting_rule_id'],true);
                if ($existing['rule_family_key']!==$family || (int)$existing['specificity']!==$specificity
                    || self::canonical($existing['match'])!==self::canonical($definition['match'])
                    || self::canonical($existing['effect'])!==self::canonical($definition['effect'])) {
                    throw new RuntimeException('A changed rule requires a new human review decision.');
                }
                return $existing;
            }
            $versions=$this->rows(self::TABLE,'rule_family_key=:family',[':family'=>$family],'ORDER BY version DESC FOR UPDATE');
            $version=$versions?(int)$versions[0]['version']+1:1;
            foreach ($versions as $old) if ($old['status']==='ACTIVE') $this->change(self::TABLE,(int)$old['id'],['status'=>'SUPERSEDED','superseded_at'=>self::now()]);
            $id=$this->insert(self::TABLE,[
                'rule_family_key'=>$family,'version'=>$version,'status'=>'ACTIVE','match_json'=>self::json($definition['match']),
                'effect_json'=>self::json($definition['effect']),'source_review_id'=>(int)$source['id'],'specificity'=>$specificity,
                'outcome_counters_json'=>self::json([]),'created_at'=>self::now(),'activated_at'=>self::now(),
            ]);
            // The source decision itself remains immutable; attach its first resulting version.
            if ($source['resulting_rule_id']===null) $this->change('amazon_return_reviews',(int)$source['id'],['resulting_rule_id'=>$id],'resulting_rule_id IS NULL');
            return $this->owned(self::TABLE,$id);
        });
    }
    public function setStatus(int $ruleId, int $expectedVersion, string $status): array
    {
        if ($status!=='DISABLED') throw new InvalidArgumentException('Only disabling an active version is supported.');
        $this->change(self::TABLE,$ruleId,['status'=>'DISABLED','disabled_at'=>self::now()],"version=:expected AND status='ACTIVE'",[':expected'=>$expectedVersion]);
        return $this->owned(self::TABLE,$ruleId);
    }
    /** Durable per-application/outcome receipts avoid counter inflation on retries. */
    public function incrementOutcome(int $ruleId, string $outcome, string $applicationKey): void
    {
        self::outcome($outcome); self::hash($applicationKey);
        $this->atomic(function () use ($ruleId,$outcome,$applicationKey): void {
            $rule=$this->owned(self::TABLE,$ruleId,true);
            $apps=$this->rows('amazon_return_rule_applications','application_key=:key AND rule_id=:rule',[':key'=>$applicationKey,':rule'=>$ruleId],'FOR UPDATE');
            if (!$apps || $apps[0]['outcome']!==$outcome) throw new RuntimeException('Outcome must match the owned application.');
            $app=$apps[0]; $counted=$app['counted_outcomes']??[];
            if (in_array($outcome,$counted,true)) return;
            $counted[]=$outcome;
            $counters=$rule['outcome_counters']??[];
            $counters[$outcome]=($counters[$outcome]??0)+1;
            $this->change(self::TABLE,$ruleId,['outcome_counters_json'=>self::json($counters)]);
            $this->change('amazon_return_rule_applications',(int)$app['id'],['counted_outcomes_json'=>self::json($counted)]);
        });
    }
}
