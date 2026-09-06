<?php
declare(strict_types=1);

require_once __DIR__.'/TenantContext.php';

/** Internal SQL helpers: all identifiers originate in repository code, never request data. */
trait SvAmazonReviewMemorySql
{
    public function __construct(private PDO $db, private SvAmazonTenantContext $context) {}

    private function sql(string $sql, array $params=[]): PDOStatement
    {
        $stmt=$this->db->prepare($sql);
        if (!$stmt || !$stmt->execute($params+[':tenant_id'=>$this->context->tenantId(), ':connection_id'=>$this->context->amazonConnectionId()])) {
            throw new RuntimeException('Review memory persistence failed.');
        }
        return $stmt;
    }

    private function scope(): string { return 'tenant_id=:tenant_id AND amazon_connection_id=:connection_id'; }

    private function rows(string $table, string $where='1=1', array $params=[], string $suffix='ORDER BY id'): array
    {
        return array_map(self::decode(...), $this->sql("SELECT * FROM {$table} WHERE ".$this->scope()." AND {$where} {$suffix}", $params)->fetchAll(PDO::FETCH_ASSOC));
    }

    private function owned(string $table, int $id, bool $lock=false): array
    {
        $rows=$this->rows($table,'id=:id',[':id'=>$id],$lock?'FOR UPDATE':'');
        if (!$rows) throw new RuntimeException('Record does not belong to the current tenant/connection.');
        return $rows[0];
    }

    private function insert(string $table, array $values): int
    {
        $fields=implode(',',array_keys($values));
        $params=[];
        foreach ($values as $key=>$value) $params[':'.$key]=$value;
        $this->sql("INSERT INTO {$table} (tenant_id,amazon_connection_id,{$fields}) VALUES (:tenant_id,:connection_id,".implode(',',array_keys($params)).')',$params);
        return (int)$this->db->lastInsertId();
    }

    private function change(string $table, int $id, array $values, string $guard='1=1', array $params=[]): void
    {
        $sets=[];
        foreach ($values as $key=>$value) { $sets[]=$key.'=:set_'.$key; $params[':set_'.$key]=$value; }
        $params[':id']=$id;
        if ($this->sql("UPDATE {$table} SET ".implode(',',$sets).' WHERE '.$this->scope()." AND id=:id AND {$guard}",$params)->rowCount()!==1) {
            throw new RuntimeException('Stale or inaccessible review memory record.',409);
        }
    }

    /** Savepoints keep a failed repository operation atomic inside a caller's decision transaction. */
    private function atomic(callable $operation): mixed
    {
        static $sequence=0;
        $savepoint='review_memory_operation_'.(++$sequence);
        $outer=$this->db->inTransaction();
        if ($outer) $this->db->exec('SAVEPOINT '.$savepoint); else $this->db->beginTransaction();
        try {
            $result=$operation();
            if ($outer) $this->db->exec('RELEASE SAVEPOINT '.$savepoint); else $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            if ($outer) {
                $this->db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);
                $this->db->exec('RELEASE SAVEPOINT '.$savepoint);
            }
            elseif ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private static function decode(array $row): array
    {
        foreach ($row as $key=>$value) {
            if (str_ends_with($key,'_json')) $row[substr($key,0,-5)]=$value===null?null:json_decode($value,true,512,JSON_THROW_ON_ERROR);
        }
        return $row;
    }
    private static function json(array $value): string
    {
        return json_encode(self::canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    private static function canonical(array $value): array
    {
        foreach ($value as &$item) if (is_array($item)) $item=self::canonical($item);
        unset($item);
        if (!array_is_list($value)) ksort($value);
        return $value;
    }
    private static function now(): string { return gmdate('Y-m-d H:i:s'); }
    private static function text(string $value, int $max=191): string
    {
        if (trim($value)==='' || strlen($value)>$max) throw new InvalidArgumentException('Invalid review memory text.');
        return $value;
    }
    private static function hash(string $value): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$value)) throw new InvalidArgumentException('Invalid SHA-256.');
        return $value;
    }
    private static function outcome(string $outcome): string
    {
        if (!in_array($outcome,['PENDING','APPROVED_PENDING_CREDIT','RECOVERED','DENIED','CLOSED_LOSS'],true)) throw new InvalidArgumentException('Invalid outcome.');
        return $outcome;
    }
}

final class SvAmazonReviewRepository
{
    use SvAmazonReviewMemorySql;
    private const TABLE='amazon_return_reviews';

    public function open(int $caseId, string $reason, string $contextHash, array $context): array
    {
        self::hash($contextHash);
        self::text($reason,96);
        return $this->atomic(function () use ($caseId,$reason,$contextHash,$context): array {
            // Serializes duplicate episodes without overwriting the first context snapshot.
            $this->owned('amazon_return_cases',$caseId,true);
            $key=hash('sha256',$caseId.'|'.$contextHash);
            $existing=$this->rows(self::TABLE,'open_key=:key',[':key'=>$key],'FOR UPDATE');
            if ($existing) return $existing[0];
            $id=$this->insert(self::TABLE,['case_id'=>$caseId,'reason'=>$reason,'context_hash'=>$contextHash,'context_json'=>self::json($context),'open_key'=>$key,'created_at'=>self::now()]);
            return $this->owned(self::TABLE,$id);
        });
    }

    public function lock(int $reviewId): array
    {
        if (!$this->db->inTransaction()) throw new RuntimeException('Review lock requires a caller transaction.');
        return $this->owned(self::TABLE,$reviewId,true);
    }
    public function find(int $reviewId): ?array { return $this->rows(self::TABLE,'id=:id',[':id'=>$reviewId],'')[0]??null; }
    public function forCase(int $caseId): array { return $this->rows(self::TABLE,'case_id=:case_id',[':case_id'=>$caseId]); }
    public function openQueue(array $filters=[]): array
    {
        $where="status='OPEN'"; $params=[];
        foreach (['reason','case_id'] as $key) if (isset($filters[$key])) { $where.=" AND {$key}=:{$key}"; $params[':'.$key]=$filters[$key]; }
        return $this->rows(self::TABLE,$where,$params,'ORDER BY created_at,id');
    }
    public function countOpen(): int { return $this->count("status='OPEN'"); }
    public function countOpenForCase(int $caseId): int { return $this->count("status='OPEN' AND case_id=:case_id",[':case_id'=>$caseId]); }
    public function countOpenByReason(string $reason): int { return $this->count("status='OPEN' AND reason=:reason",[':reason'=>$reason]); }
    public function countAiFailures(): int { return (int)$this->sql('SELECT COALESCE(SUM(ai_error_count),0) FROM '.self::TABLE.' WHERE '.$this->scope())->fetchColumn(); }
    private function count(string $where, array $params=[]): int { return (int)$this->sql('SELECT COUNT(*) FROM '.self::TABLE.' WHERE '.$this->scope().' AND '.$where,$params)->fetchColumn(); }

    public function decide(int $reviewId, int $expectedVersion, array $decision): array
    {
        if (!in_array($decision['decision_mode']??null,['APPROVED','EDITED_APPROVED','REJECTED','WAIT','EXCEPTION'],true)) throw new InvalidArgumentException('Invalid decision mode.');
        foreach (['final_action','actor','source_version'] as $field) self::text((string)($decision[$field]??''));
        return $this->mutate($reviewId,$expectedVersion,[
            'status'=>'DECIDED','open_key'=>null,'human_decision_json'=>self::json($decision),'decision_mode'=>$decision['decision_mode'],
            'actor'=>$decision['actor'],'source_version'=>$decision['source_version'],
            'evidence_refs_json'=>self::json($decision['evidence_refs']??[]),
            'affected_case_preview_json'=>self::json($decision['affected_case_preview']??[]),'decided_at'=>self::now(),
        ]);
    }
    public function saveSuggestion(int $reviewId, int $expectedVersion, array $suggestion, string $model): array
    {
        return $this->mutate($reviewId,$expectedVersion,['ai_suggestion_json'=>self::json($suggestion),'ai_provider'=>'OPENAI','ai_model'=>self::text($model),'ai_suggested_at'=>self::now()]);
    }
    public function recordAiFailure(int $reviewId, int $expectedVersion, string $errorClass): array
    {
        if (!preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]{0,190}$/D',$errorClass)) throw new InvalidArgumentException('Error telemetry accepts a class name only.');
        return $this->atomic(function () use ($reviewId,$expectedVersion,$errorClass): array {
            $row=$this->owned(self::TABLE,$reviewId,true);
            return $this->mutate($reviewId,$expectedVersion,['ai_error_count'=>min(2147483647,(int)$row['ai_error_count']+1),'ai_error_class'=>$errorClass,'ai_error_at'=>self::now()]);
        });
    }
    private function mutate(int $id, int $version, array $values): array
    {
        return $this->atomic(function () use ($id,$version,$values): array {
            $this->change(self::TABLE,$id,$values+['version'=>$version+1],"status='OPEN' AND version=:expected",[':expected'=>$version]);
            return $this->owned(self::TABLE,$id);
        });
    }
}
