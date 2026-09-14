<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';

final class SvAmazonReturnCaseRepository
{
    private const PATCHABLE = [
        'quantity_ordered','quantity_refunded','quantity_received','program','refund_initiator','refund_at','seller_debit_at',
        'refund_amount','expected_reimbursement_amount','reconciled_credit_amount','physical_status','state',
        'policy_version_id','eligibility_at','next_action_at','safe_t_id','support_case_id',
        'repeated_denial_count','last_denial_fingerprint','appeal_deadline_at','terminal_reason','closed_at',
    ];

    private const INSERTABLE = [
        'amazon_order_id','amazon_order_item_id','marketplace_id','sku','asin','quantity_ordered',
        'quantity_refunded','quantity_received','program','refund_initiator','refund_at','seller_debit_at',
        'refund_amount','expected_reimbursement_amount','reconciled_credit_amount','physical_status','state',
        'policy_version_id','eligibility_at','next_action_at','safe_t_id','support_case_id',
        'repeated_denial_count','last_denial_fingerprint','appeal_deadline_at','terminal_reason','closed_at',
    ];

    public function __construct(
        private PDO $db,
        private SvAmazonTenantContext $context
    ) {}

    /** @return array<string,mixed>|null */
    public function find(int $caseId): ?array
    {
        $caseId = $this->positiveId($caseId, 'case ID');
        $stmt = $this->prepare(
            'SELECT * FROM amazon_return_cases WHERE id=:id AND tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id LIMIT 1'
        );
        $stmt->execute($this->scopeParams([':id'=>$caseId]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findByOrderItem(string $orderId, string $itemId): ?array
    {
        $orderId = $this->requiredText($orderId, 'Amazon order ID', 32);
        $itemId = $this->requiredText($itemId, 'Amazon order item ID', 64);
        $stmt = $this->prepare(
            'SELECT * FROM amazon_return_cases WHERE amazon_order_id=:order_id '
            . 'AND amazon_order_item_id=:item_id AND tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id LIMIT 1'
        );
        $stmt->execute($this->scopeParams([':order_id'=>$orderId, ':item_id'=>$itemId]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findSingleByOrder(string $orderId): ?array
    {
        $orderId = $this->requiredText($orderId, 'Amazon order ID', 32);
        $stmt = $this->prepare(
            'SELECT * FROM amazon_return_cases WHERE amazon_order_id=:order_id '
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY id LIMIT 2'
        );
        $stmt->execute($this->scopeParams([':order_id'=>$orderId]));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    /** @return list<array<string,mixed>> */
    public function forOrder(string $orderId): array
    {
        $orderId = $this->requiredText($orderId, 'Amazon order ID', 32);
        $stmt = $this->prepare(
            'SELECT * FROM amazon_return_cases WHERE amazon_order_id=:order_id '
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id ORDER BY id'
        );
        $stmt->execute($this->scopeParams([':order_id'=>$orderId]));
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
    }

    public function marketplaceId(): string
    {
        $stmt = $this->prepare(
            "SELECT marketplace_id FROM amazon_return_connections WHERE tenant_id=:tenant_id "
            . "AND id=:amazon_connection_id AND status='ACTIVE' LIMIT 1"
        );
        $stmt->execute($this->scopeParams());
        $value = $stmt->fetchColumn();
        if (!is_scalar($value) || trim((string)$value) === '') {
            throw new RuntimeException('Bound Amazon connection has no active marketplace.');
        }
        return $this->requiredText($value, 'Marketplace ID', 32);
    }

    public function resolvePlaceholder(string $orderId, string $placeholder, string $itemId): ?int
    {
        $orderId = $this->requiredText($orderId, 'Amazon order ID', 32);
        $placeholder = $this->requiredText($placeholder, 'Placeholder item ID', 64);
        $itemId = $this->requiredText($itemId, 'Amazon order item ID', 64);
        if ($placeholder === $itemId) {
            $existing = $this->findByOrderItem($orderId, $itemId);
            return isset($existing['id']) ? (int)$existing['id'] : null;
        }
        $stmt = $this->prepare(
            'UPDATE amazon_return_cases SET amazon_order_item_id=:item_id,updated_at=UTC_TIMESTAMP() '
            . 'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'AND amazon_order_id=:order_id AND amazon_order_item_id=:placeholder '
            . 'AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM amazon_return_cases '
            . 'WHERE tenant_id=:existing_tenant_id AND amazon_connection_id=:existing_connection_id '
            . 'AND amazon_order_id=:existing_order_id AND amazon_order_item_id=:existing_item_id LIMIT 1) existing)'
        );
        $stmt->execute($this->scopeParams([
            ':order_id'=>$orderId,
            ':placeholder'=>$placeholder,
            ':item_id'=>$itemId,
            ':existing_tenant_id'=>$this->context->tenantId(),
            ':existing_connection_id'=>$this->context->amazonConnectionId(),
            ':existing_order_id'=>$orderId,
            ':existing_item_id'=>$itemId,
        ]));
        $resolved = $this->findByOrderItem($orderId, $itemId);
        return isset($resolved['id']) ? (int)$resolved['id'] : null;
    }

    /** @return array{items:list<array<string,mixed>>,page:int,per_page:int,total:int} */
    public function search(array $filters,int $page=1,int $perPage=50): array
    {
        $page=max(1,$page);$perPage=max(1,min(1000,$perPage));
        $where=['c.tenant_id=:tenant_id','c.amazon_connection_id=:amazon_connection_id'];
        $params=$this->scopeParams();
        $allowed=['q','case_ids','safe_t_id','state','review_status','program','physical_status','deadline','learned_rule','min_outstanding','max_outstanding'];
        foreach(array_keys($filters) as $key)if(!in_array($key,$allowed,true))throw new InvalidArgumentException('Unsupported case search filter: '.$key);
        if(isset($filters['q'])){
            $q='%'.$this->requiredText((string)$filters['q'],'search query',96).'%';
            $where[]='(c.amazon_order_id LIKE :q_order OR c.safe_t_id LIKE :q_safe_t OR c.sku LIKE :q_sku OR c.asin LIKE :q_asin)';
            foreach([':q_order',':q_safe_t',':q_sku',':q_asin'] as $placeholder)$params[$placeholder]=$q;
        }
        if(array_key_exists('case_ids',$filters)){
            if(!is_array($filters['case_ids']))throw new InvalidArgumentException('case_ids must be an internal array filter.');
            $caseIds=[];
            foreach($filters['case_ids'] as $rawId){
                $parsed=filter_var($rawId,FILTER_VALIDATE_INT);
                if($parsed===false || $parsed<1)throw new InvalidArgumentException('case_ids contains an invalid ID.');
                $caseIds[]=(int)$parsed;
            }
            $caseIds=array_values(array_unique($caseIds));
            if($caseIds===[]){
                $where[]='1=0';
            }else{
                $placeholders=[];
                foreach($caseIds as $index=>$caseId){
                    $name=':case_id_'.$index;$placeholders[]=$name;$params[$name]=$caseId;
                }
                $where[]='c.id IN ('.implode(',',$placeholders).')';
            }
        }
        foreach(['safe_t_id','state','program','physical_status'] as $field){if(!isset($filters[$field]))continue;$where[]='c.'.$field.'=:'.$field;$params[':'.$field]=$filters[$field];}
        if(isset($filters['review_status'])){$where[]='EXISTS (SELECT 1 FROM amazon_return_reviews r WHERE r.tenant_id=c.tenant_id AND r.amazon_connection_id=c.amazon_connection_id AND r.case_id=c.id AND r.status=:review_status)';$params[':review_status']=$filters['review_status'];}
        $deadline='COALESCE(c.appeal_deadline_at,c.next_action_at,c.eligibility_at)';
        if(($filters['deadline']??null)==='overdue')$where[]="$deadline<UTC_TIMESTAMP()";
        elseif(($filters['deadline']??null)==='today')$where[]="DATE($deadline)=UTC_DATE()";
        elseif(($filters['deadline']??null)==='7d')$where[]="$deadline BETWEEN UTC_TIMESTAMP() AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY)";
        if(($filters['learned_rule']??null)==='applied')$where[]='EXISTS (SELECT 1 FROM amazon_return_rule_applications a WHERE a.tenant_id=c.tenant_id AND a.amazon_connection_id=c.amazon_connection_id AND a.case_id=c.id)';
        elseif(($filters['learned_rule']??null)==='none')$where[]='NOT EXISTS (SELECT 1 FROM amazon_return_rule_applications a WHERE a.tenant_id=c.tenant_id AND a.amazon_connection_id=c.amazon_connection_id AND a.case_id=c.id)';
        elseif(($filters['learned_rule']??null)==='conflict')$where[]="EXISTS (SELECT 1 FROM amazon_return_reviews r WHERE r.tenant_id=c.tenant_id AND r.amazon_connection_id=c.amazon_connection_id AND r.case_id=c.id AND r.status='OPEN' AND r.reason='LEARNED_RULE_CONFLICT')";
        $outstanding='GREATEST((CASE WHEN c.expected_reimbursement_amount>0 THEN c.expected_reimbursement_amount ELSE c.refund_amount END)-c.reconciled_credit_amount,0)';
        if(isset($filters['min_outstanding'])){$where[]="$outstanding>=:min_outstanding";$params[':min_outstanding']=$filters['min_outstanding'];}
        if(isset($filters['max_outstanding'])){$where[]="$outstanding<=:max_outstanding";$params[':max_outstanding']=$filters['max_outstanding'];}
        $whereSql=implode(' AND ',$where);
        $count=$this->prepare('SELECT COUNT(*) FROM amazon_return_cases c WHERE '.$whereSql);$count->execute($params);$total=max(0,(int)$count->fetchColumn());
        $offset=($page-1)*$perPage;
        $order="ORDER BY CASE WHEN EXISTS (SELECT 1 FROM amazon_return_reviews rr WHERE rr.tenant_id=c.tenant_id AND rr.amazon_connection_id=c.amazon_connection_id AND rr.case_id=c.id AND rr.status='OPEN') THEN 0 ELSE 1 END, CASE WHEN $deadline<UTC_TIMESTAMP() THEN 0 ELSE 1 END, $deadline IS NULL, $deadline, $outstanding DESC, c.updated_at DESC,c.id DESC";
        $stmt=$this->prepare('SELECT c.* FROM amazon_return_cases c WHERE '.$whereSql.' '.$order.' LIMIT '.$perPage.' OFFSET '.$offset);$stmt->execute($params);
        return ['items'=>array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array')),'page'=>$page,'per_page'=>$perPage,'total'=>$total];
    }

    /** @return list<int> */
    public function caseIdsForReference(string $term,bool $exact): array
    {
        $term=$this->requiredText($term,'case reference',96);
        $pattern=$exact?$term:'%'.strtr($term,['\\'=>'\\\\','%'=>'\\%','_'=>'\\_']).'%';
        $stmt=$this->prepare(
            'SELECT id FROM amazon_return_cases WHERE tenant_id=:tenant_id '
            .'AND amazon_connection_id=:amazon_connection_id AND ('
            .'amazon_order_id LIKE :ref_order OR safe_t_id LIKE :ref_safe_t '
            .'OR sku LIKE :ref_sku OR asin LIKE :ref_asin) ORDER BY id LIMIT 1000'
        );
        $stmt->execute($this->scopeParams([
            ':ref_order'=>$pattern,':ref_safe_t'=>$pattern,':ref_sku'=>$pattern,':ref_asin'=>$pattern,
        ]));
        return array_values(array_unique(array_filter(
            array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)),
            static fn(int $id):bool=>$id>0
        )));
    }

    /** @return list<array<string,mixed>> */
    public function openCases(int $limit = 250): array
    {
        $limit = max(1, min(1000, $limit));
        $stmt = $this->prepare(
            'SELECT * FROM amazon_return_cases WHERE closed_at IS NULL '
            . 'AND (refund_at IS NULL OR refund_at > DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)) '
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY COALESCE(next_action_at,updated_at),id LIMIT ' . $limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), 'is_array'));
    }

    /** @param list<string> $orderIds @return array<string,list<int>> */
    public function caseIdsForOrders(array $orderIds): array
    {
        $normalized = [];
        foreach ($orderIds as $orderId) {
            if (!is_string($orderId)) throw new InvalidArgumentException('Amazon order IDs must be strings.');
            $normalized[] = $this->requiredText($orderId, 'Amazon order ID', 32);
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        if ($normalized === []) return [];
        $placeholders=[];$params=$this->scopeParams();
        foreach($normalized as $i=>$orderId){$name=':order_'.$i;$placeholders[]=$name;$params[$name]=$orderId;}
        $stmt=$this->prepare('SELECT amazon_order_id,id FROM amazon_return_cases WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id AND amazon_order_id IN ('.implode(',',$placeholders).') ORDER BY amazon_order_id,id');
        $stmt->execute($params);
        $out=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){if(!is_array($row))continue;$out[(string)$row['amazon_order_id']][]=(int)$row['id'];}
        return $out;
    }

    /** @return array<string,mixed> */
    public function operationalSummary(): array
    {
        $exposure='GREATEST((CASE WHEN expected_reimbursement_amount>0 THEN expected_reimbursement_amount ELSE refund_amount END)-reconciled_credit_amount,0)';
        $stmt=$this->prepare(
            "SELECT COUNT(*) cases,SUM(CASE WHEN closed_at IS NULL THEN 1 ELSE 0 END) open_cases,"
            ."COALESCE(SUM(CASE WHEN state='RECOVERED' THEN reconciled_credit_amount ELSE 0 END),0) recovered,"
            ."COALESCE(SUM(CASE WHEN state='CLOSED_LOSS' THEN GREATEST($exposure,0) ELSE 0 END),0) loss,"
            ."COALESCE(SUM(CASE WHEN closed_at IS NULL THEN GREATEST($exposure,0) ELSE 0 END),0) open_exposure,"
            ."SUM(CASE WHEN state='RECOVERED' AND GREATEST($exposure,0)>0 THEN 1 ELSE 0 END) recovered_with_gap,"
            ."SUM(CASE WHEN reconciled_credit_amount>0 AND state NOT IN ('RECOVERED','CREDIT_PENDING') THEN 1 ELSE 0 END) credit_without_reconciliation "
            ."FROM amazon_return_cases WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id"
        );
        $stmt->execute($this->scopeParams());
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:[];
    }

    /** @return list<array<string,mixed>> */
    public function staleCasesForHealth(int $days=3,int $limit=100): array
    {
        $days=max(1,min(365,$days));$limit=max(1,min(1000,$limit));
        $stmt=$this->prepare(
            'SELECT * FROM amazon_return_cases WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'AND closed_at IS NULL AND updated_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.$days.' DAY) '
            . 'ORDER BY updated_at,id LIMIT '.$limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    public function create(array $data): int
    {
        $payload=$this->validatedInsert($data);
        $payload['tenant_id']=$this->context->tenantId();
        $payload['amazon_connection_id']=$this->context->amazonConnectionId();
        $columns=array_keys($payload);
        $stmt=$this->prepare('INSERT INTO amazon_return_cases ('.implode(',',$columns).') VALUES ('.implode(',',array_map(static fn(string $c):string=>':'.$c,$columns)).')');
        $params=[];foreach($payload as $column=>$value)$params[':'.$column]=$value;
        $stmt->execute($params);
        $id=(int)$this->db->lastInsertId();
        if($id<1)throw new RuntimeException('Failed to create Amazon return case.');
        return $id;
    }

    public function update(int $caseId,array $patch): void
    {
        $caseId=$this->positiveId($caseId,'case ID');
        if($patch===[])return;
        $sets=[];$params=[':id'=>$caseId];
        foreach($patch as $field=>$value){
            if(!in_array($field,self::PATCHABLE,true))throw new InvalidArgumentException('Unsupported case patch field: '.$field);
            $sets[]=$field.'=:'.$field;$params[':'.$field]=$this->validatedField($field,$value);
        }
        $sets[]='updated_at=UTC_TIMESTAMP()';
        $stmt=$this->prepare('UPDATE amazon_return_cases SET '.implode(',',$sets).' WHERE id=:id AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id');
        $stmt->execute($this->scopeParams($params));
        if($stmt->rowCount()===0 && $this->find($caseId)===null)throw new RuntimeException('Amazon return case not found in tenant scope.');
    }

    private function validatedInsert(array $data): array
    {
        $unknown=array_diff(array_keys($data),self::INSERTABLE);
        if($unknown!==[])throw new InvalidArgumentException('Unsupported case insert fields: '.implode(',',$unknown));
        $required=['amazon_order_id','amazon_order_item_id','marketplace_id'];
        foreach($required as $field)if(!isset($data[$field]))throw new InvalidArgumentException('Missing required case field: '.$field);
        $defaults=[
            'quantity_ordered'=>1,'quantity_refunded'=>0,'quantity_received'=>0,'program'=>'UNKNOWN',
            'refund_initiator'=>'UNKNOWN','refund_at'=>null,'seller_debit_at'=>null,
            'refund_amount'=>'0.00','expected_reimbursement_amount'=>'0.00','reconciled_credit_amount'=>'0.00',
            'physical_status'=>'NOT_RECEIVED','state'=>'AWAITING_RETURN','policy_version_id'=>null,'eligibility_at'=>null,'next_action_at'=>null,
            'safe_t_id'=>null,'support_case_id'=>null,'repeated_denial_count'=>0,'last_denial_fingerprint'=>null,
            'appeal_deadline_at'=>null,'terminal_reason'=>null,'closed_at'=>null,
        ];
        $payload=[];
        foreach($defaults as $field=>$default)$payload[$field]=$this->validatedField($field,$data[$field]??$default);
        foreach(['amazon_order_id','amazon_order_item_id','marketplace_id','sku','asin'] as $field){
            if(array_key_exists($field,$data))$payload[$field]=$this->validatedField($field,$data[$field]);
        }
        return $payload;
    }

    private function validatedField(string $field,mixed $value): mixed
    {
        return match($field){
            'amazon_order_id'=>$this->requiredText($value,'Amazon order ID',32),
            'amazon_order_item_id'=>$this->requiredText($value,'Amazon order item ID',64),
            'marketplace_id'=>$this->requiredText($value,'Marketplace ID',32),
            'sku','asin','safe_t_id','support_case_id','last_denial_fingerprint','terminal_reason'=>$this->nullableText($value,$field,191),
            'quantity_ordered'=>$this->nonNegativeInt($value,$field),
            'quantity_refunded','quantity_received','repeated_denial_count'=>$this->nonNegativeInt($value,$field),
            'refund_amount','expected_reimbursement_amount','reconciled_credit_amount'=>$this->decimal($value,$field),
            'program'=>$this->enum($value,['UNKNOWN','FBA','DELIVERY_BY_AMAZON','FBA_ONSITE'],$field),
            'refund_initiator'=>$this->enum($value,['UNKNOWN','AMAZON_AUTOMATIC','AMAZON_CUSTOMER_SERVICE','AMAZON_INITIATED','SELLER_INITIATED','A_TO_Z'],$field),
            'physical_status'=>$this->enum($value,['NOT_RECEIVED','IN_TRANSIT','CARRIER_DELIVERED_PENDING_PHYSICAL','RECEIVED_OK','RECEIVED_DISCREPANT'],$field),
            'state'=>$this->enum($value,['AWAITING_RETURN','IN_TRANSIT','CARRIER_DELIVERED_PENDING_PHYSICAL','RECEIVED_OK','RECEIVED_DISCREPANT','POLICY_REVIEW_REQUIRED','SAFE_T_ELIGIBLE','SAFE_T_READY','SAFE_T_SUBMITTED','SAFE_T_APPROVED','SAFE_T_DENIED','SAFE_T_INFO_REQUESTED','APPEAL_REQUIRED','APPEAL_SUBMITTED','APPEAL_APPROVED','APPEAL_DENIED_FINAL','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','CREDIT_PENDING','RECOVERED','SUPPORT_ESCALATION','CLOSED_LOSS'],$field),
            'policy_version_id'=>$value===null?null:$this->positiveId($value,$field),
            'refund_at','seller_debit_at','eligibility_at','next_action_at','appeal_deadline_at','closed_at'=>$this->nullableDate($value,$field),
            default=>throw new InvalidArgumentException('Unsupported case field: '.$field),
        };
    }

    private function prepare(string $sql): PDOStatement
    {
        $stmt=$this->db->prepare($sql);
        if(!$stmt)throw new RuntimeException('Failed to prepare Amazon return case query.');
        return $stmt;
    }

    private function scopeParams(array $params=[]): array
    {
        $params[':tenant_id']=$this->context->tenantId();
        $params[':amazon_connection_id']=$this->context->amazonConnectionId();
        return $params;
    }

    private function positiveId(mixed $value,string $name): int
    {
        $parsed=filter_var($value,FILTER_VALIDATE_INT);
        if($parsed===false || $parsed<1)throw new InvalidArgumentException("Invalid {$name}.");
        return (int)$parsed;
    }

    private function requiredText(mixed $value,string $name,int $max): string
    {
        if(!is_string($value) && !is_int($value))throw new InvalidArgumentException("Invalid {$name}.");
        $text=trim((string)$value);
        if($text==='' || strlen($text)>$max)throw new InvalidArgumentException("Invalid {$name}.");
        return $text;
    }

    private function nullableText(mixed $value,string $name,int $max): ?string
    {
        if($value===null || $value==='')return null;
        return $this->requiredText($value,$name,$max);
    }

    private function nonNegativeInt(mixed $value,string $name): int
    {
        $parsed=filter_var($value,FILTER_VALIDATE_INT);
        if($parsed===false || $parsed<0)throw new InvalidArgumentException("Invalid {$name}.");
        return (int)$parsed;
    }

    private function decimal(mixed $value,string $name): string
    {
        if(!is_numeric($value))throw new InvalidArgumentException("Invalid {$name}.");
        $number=(float)$value;
        if($number<0)throw new InvalidArgumentException("Invalid {$name}.");
        return number_format($number,2,'.','');
    }

    private function nullableDate(mixed $value,string $name): ?string
    {
        if($value===null || $value==='')return null;
        if(!is_string($value))throw new InvalidArgumentException("Invalid {$name}.");
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if(!$date || ($errors!==false && ($errors['warning_count']>0 || $errors['error_count']>0)) || $date->format('Y-m-d H:i:s')!==$value)throw new InvalidArgumentException("Invalid {$name}.");
        return $value;
    }

    private function enum(mixed $value,array $allowed,string $name): string
    {
        if(!is_string($value) || !in_array($value,$allowed,true))throw new InvalidArgumentException("Invalid {$name}.");
        return $value;
    }
}
