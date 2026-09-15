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

        $params = $this->scopeParams();
        $placeholders = [];
        foreach ($normalized as $index=>$orderId) {
            $name = ':order_' . $index;
            $placeholders[] = $name;
            $params[$name] = $orderId;
        }
        $stmt = $this->prepare(
            'SELECT amazon_order_id,id FROM amazon_return_cases WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id AND amazon_order_id IN ('
            . implode(',', $placeholders) . ') ORDER BY amazon_order_id,id'
        );
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) continue;
            $orderId = trim((string)($row['amazon_order_id'] ?? ''));
            $id = (int)($row['id'] ?? 0);
            if ($orderId === '' || $id < 1) continue;
            $result[$orderId] ??= [];
            $result[$orderId][] = $id;
        }
        return $result;
    }

    public function countAll(): int
    {
        $stmt=$this->prepare(
            'SELECT COUNT(*) FROM amazon_return_cases WHERE tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id'
        );
        $stmt->execute($this->scopeParams());
        return max(0,(int)$stmt->fetchColumn());
    }

    /** @return list<string> */
    public function financialOrderIdsAfter(string $after = '', int $limit = 25): array
    {
        $limit = max(1, min(1000, $limit));
        if ($after !== '') $after = $this->requiredText($after, 'Financial order cursor', 32);
        $stmt = $this->prepare(
            'SELECT DISTINCT amazon_order_id FROM amazon_return_cases '
            . 'WHERE (closed_at IS NULL OR expected_reimbursement_amount>0) '
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'AND amazon_order_id>:after_order_id ORDER BY amazon_order_id LIMIT ' . $limit
        );
        $stmt->execute($this->scopeParams([':after_order_id'=>$after]));
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = trim((string)($row['amazon_order_id'] ?? ''));
            if ($id !== '') $ids[] = $id;
        }
        return $ids;
    }

    /** @return list<string> */
    public function openOrderIds(int $limit=25): array
    {
        $limit=max(1,min(1000,$limit));
        $stmt=$this->prepare(
            'SELECT DISTINCT amazon_order_id FROM amazon_return_cases WHERE closed_at IS NULL '
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY amazon_order_id LIMIT '.$limit
        );
        $stmt->execute($this->scopeParams());
        $ids=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=trim((string)($row['amazon_order_id'] ?? ''));
            if($id!=='')$ids[]=$id;
        }
        return $ids;
    }

    /** @return list<array<string,mixed>> */
    public function financialCasesAfter(int $afterId=0, int $limit=250): array
    {
        $afterId=max(0,$afterId);
        $limit=max(1,min(1000,$limit));
        $stmt=$this->prepare(
            'SELECT * FROM amazon_return_cases WHERE expected_reimbursement_amount>0 AND id>:after_id '
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY id LIMIT '.$limit
        );
        $stmt->execute($this->scopeParams([':after_id'=>$afterId]));
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function casesWithSafeTId(int $limit=250): array
    {
        $limit=max(1,min(1000,$limit));
        $stmt=$this->prepare(
            "SELECT * FROM amazon_return_cases WHERE closed_at IS NULL AND safe_t_id IS NOT NULL AND safe_t_id<>'' "
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY id LIMIT '.$limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function casesWithSupportCaseId(int $limit=250): array
    {
        $limit=max(1,min(1000,$limit));
        $stmt=$this->prepare(
            "SELECT * FROM amazon_return_cases WHERE closed_at IS NULL AND support_case_id IS NOT NULL AND support_case_id<>'' "
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY id LIMIT '.$limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function casesWithoutSafeTId(int $limit=250): array
    {
        $limit=max(1,min(1000,$limit));
        $stmt=$this->prepare(
            "SELECT * FROM amazon_return_cases WHERE closed_at IS NULL AND (safe_t_id IS NULL OR safe_t_id='') "
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY id LIMIT '.$limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $exposure='(CASE WHEN c.expected_reimbursement_amount>0 THEN c.expected_reimbursement_amount ELSE c.refund_amount END-c.reconciled_credit_amount)';
        $unclassified="(c.program='UNKNOWN' OR (c.program IN ('STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON') AND (c.refund_initiator='UNKNOWN' OR c.seller_debit_at IS NULL))) AND c.closed_at IS NULL AND c.state IN ('REFUND_DETECTED','AWAITING_RETURN','NO_RETURN','IN_TRANSIT','RECEIVED_DISCREPANT','SAFE_T_ELIGIBLE','SAFE_T_READY','POLICY_REVIEW_REQUIRED','BLOCKED_REVIEW')";
        $eligible="c.state IN ('SAFE_T_ELIGIBLE','SAFE_T_READY') AND c.safe_t_id IS NULL";
        $expired="c.refund_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY) AND c.appeal_deadline_at IS NOT NULL AND c.appeal_deadline_at<UTC_TIMESTAMP() AND c.state IN ('SAFE_T_DENIED','SAFE_T_INFO_REQUESTED','APPEAL_REQUIRED') AND NOT EXISTS (SELECT 1 FROM amazon_return_outbox o WHERE o.tenant_id=c.tenant_id AND o.amazon_connection_id=c.amazon_connection_id AND o.case_id=c.id AND o.kind='SAFE_T_APPEAL' AND o.status IN ('PENDING','PROCESSING','SUCCEEDED'))";
        $creditMismatch="c.closed_at IS NULL AND c.state<>'RECOVERED' AND $exposure<=0";
        $concluded="c.closed_at IS NOT NULL OR c.state IN ('RECOVERED','CLOSED_LOSS','RECEIVED_OK')";
        $stmt=$this->prepare("SELECT COUNT(*) total_cases,"
            ."COALESCE(SUM(GREATEST($exposure,0)),0) at_risk,"
            ."COALESCE(SUM(CASE WHEN c.state IN ('SAFE_T_ELIGIBLE','SAFE_T_READY') THEN GREATEST($exposure,0) ELSE 0 END),0) eligible_now,"
            ."COALESCE(SUM(CASE WHEN c.state='SAFE_T_SUBMITTED' THEN GREATEST($exposure,0) ELSE 0 END),0) safe_t_submitted,"
            ."COALESCE(SUM(CASE WHEN c.state='SAFE_T_DENIED' THEN GREATEST($exposure,0) ELSE 0 END),0) denied,"
            ."COALESCE(SUM(CASE WHEN c.state IN ('APPEAL_REQUIRED','APPEAL_SUBMITTED') THEN GREATEST($exposure,0) ELSE 0 END),0) appeal,"
            ."COALESCE(SUM(CASE WHEN c.state='SUPPORT_ESCALATION' THEN GREATEST($exposure,0) ELSE 0 END),0) support,"
            ."COALESCE(SUM(CASE WHEN c.state IN ('SAFE_T_APPROVED','APPEAL_APPROVED','CREDIT_PENDING') THEN GREATEST($exposure,0) ELSE 0 END),0) approved_awaiting_credit,"
            ."COALESCE(SUM(CASE WHEN c.state='RECOVERED' THEN c.reconciled_credit_amount ELSE 0 END),0) recovered,"
            ."COALESCE(SUM(CASE WHEN c.state='CLOSED_LOSS' THEN GREATEST($exposure,0) ELSE 0 END),0) loss,"
            ."SUM(CASE WHEN $concluded THEN 1 ELSE 0 END) concluded_cases,"
            ."SUM(CASE WHEN NOT ($concluded) AND NOT EXISTS (SELECT 1 FROM amazon_return_reviews ar WHERE ar.tenant_id=c.tenant_id AND ar.amazon_connection_id=c.amazon_connection_id AND ar.case_id=c.id AND ar.status='OPEN') THEN 1 ELSE 0 END) automatic_work_cases,"
            ."SUM(CASE WHEN $unclassified THEN 1 ELSE 0 END) unclassified,"
            ."COALESCE(SUM(CASE WHEN $unclassified THEN GREATEST($exposure,0) ELSE 0 END),0) unclassified_amount,"
            ."SUM(CASE WHEN $eligible THEN 1 ELSE 0 END) eligible_without_action,"
            ."COALESCE(SUM(CASE WHEN $eligible THEN GREATEST($exposure,0) ELSE 0 END),0) eligible_without_action_amount,"
            ."SUM(CASE WHEN $expired THEN 1 ELSE 0 END) expired_without_treatment,"
            ."COALESCE(SUM(CASE WHEN $expired THEN GREATEST($exposure,0) ELSE 0 END),0) expired_without_treatment_amount,"
            ."SUM(CASE WHEN $creditMismatch THEN 1 ELSE 0 END) credit_without_reconciliation,"
            ."COALESCE(SUM(CASE WHEN $creditMismatch THEN GREATEST($exposure,0) ELSE 0 END),0) credit_without_reconciliation_amount "
            ."FROM amazon_return_cases c WHERE c.tenant_id=:tenant_id AND c.amazon_connection_id=:amazon_connection_id"
        );
        $stmt->execute($this->scopeParams());
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:[];
    }

    /** @return list<array<string,mixed>> */
    public function automationPreview(int $limit=6): array
    {
        $limit=max(1,min(25,$limit));
        $exposure='GREATEST((CASE WHEN c.expected_reimbursement_amount>0 THEN c.expected_reimbursement_amount ELSE c.refund_amount END)-c.reconciled_credit_amount,0)';
        $due='COALESCE(c.next_action_at,c.appeal_deadline_at,c.eligibility_at)';
        $stmt=$this->prepare(
            'SELECT c.id,c.amazon_order_id,c.state,c.physical_status,c.safe_t_id,c.next_action_at,c.appeal_deadline_at,c.eligibility_at,'
            ."$exposure outstanding_amount,c.updated_at FROM amazon_return_cases c "
            ."WHERE c.tenant_id=:tenant_id AND c.amazon_connection_id=:amazon_connection_id "
            ."AND c.closed_at IS NULL AND c.state NOT IN ('RECOVERED','CLOSED_LOSS','RECEIVED_OK') "
            ."AND NOT EXISTS (SELECT 1 FROM amazon_return_reviews r WHERE r.tenant_id=c.tenant_id AND r.amazon_connection_id=c.amazon_connection_id AND r.case_id=c.id AND r.status='OPEN') "
            ."ORDER BY CASE WHEN $due IS NOT NULL AND $due<UTC_TIMESTAMP() THEN 0 ELSE 1 END,$due IS NULL,$due,$exposure DESC,c.updated_at DESC LIMIT ".$limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function upcomingDeadlines(int $limit=8): array
    {
        $limit=max(1,min(50,$limit));
        $exposure='GREATEST((CASE WHEN c.expected_reimbursement_amount>0 THEN c.expected_reimbursement_amount ELSE c.refund_amount END)-c.reconciled_credit_amount,0)';
        $sentinel="'9999-12-31 23:59:59'";
        $due="LEAST(COALESCE(c.appeal_deadline_at,$sentinel),COALESCE(c.next_action_at,$sentinel),COALESCE(c.eligibility_at,$sentinel))";
        $kind="CASE WHEN c.appeal_deadline_at IS NOT NULL AND c.appeal_deadline_at=$due THEN 'APPEAL_DEADLINE' WHEN c.next_action_at IS NOT NULL AND c.next_action_at=$due THEN 'NEXT_ACTION' ELSE 'ELIGIBILITY' END";
        $stmt=$this->prepare(
            'SELECT c.id,c.amazon_order_id,c.state,c.safe_t_id,'
            ."$kind due_kind,$due due_at,$exposure outstanding_amount "
            .'FROM amazon_return_cases c '
            .'WHERE c.tenant_id=:tenant_id AND c.amazon_connection_id=:amazon_connection_id '
            ."AND c.closed_at IS NULL AND c.state NOT IN ('RECOVERED','CLOSED_LOSS','RECEIVED_OK') "
            .'AND (c.appeal_deadline_at IS NOT NULL OR c.next_action_at IS NOT NULL OR c.eligibility_at IS NOT NULL) '
            ."ORDER BY CASE WHEN $due<UTC_TIMESTAMP() THEN 0 ELSE 1 END,$due,$exposure DESC,c.id DESC LIMIT ".$limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit=50): array
    {
        $limit=max(1,min(250,$limit));
        $stmt=$this->prepare(
            'SELECT id,amazon_order_id,amazon_order_item_id,sku,state,physical_status,eligibility_at,'
            . 'safe_t_id,support_case_id,refund_amount,expected_reimbursement_amount,'
            . 'reconciled_credit_amount,updated_at FROM amazon_return_cases '
            . 'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'ORDER BY updated_at DESC,id DESC LIMIT '.$limit
        );
        $stmt->execute($this->scopeParams());
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),'is_array'));
    }

    public function countOpen(): int
    {
        $stmt = $this->prepare(
            'SELECT COUNT(*) FROM amazon_return_cases WHERE closed_at IS NULL '
            . 'AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id'
        );
        $stmt->execute($this->scopeParams());
        return max(0, (int)$stmt->fetchColumn());
    }
    public function earliestObservedDate(): ?string
    {
        $stmt = $this->prepare(
            'SELECT MIN(COALESCE(refund_at,seller_debit_at,created_at)) FROM amazon_return_cases '
            . 'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id'
        );
        $stmt->execute($this->scopeParams());
        $value = $stmt->fetchColumn();
        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : null;
    }

    /** @param array<string,mixed> $values */
    public function insert(array $values): int
    {
        $row = $this->normalizeInsert($values);
        $stmt = $this->prepare($this->insertSql(false));
        $stmt->execute($this->insertParams($row));
        $id = (int)$this->db->lastInsertId();
        if ($id < 1) throw new RuntimeException('Case insert did not return an ID.');
        return $id;
    }

    /** @param array<string,mixed> $values */
    public function upsertOrderItem(array $values): int
    {
        $row = $this->normalizeInsert($values);
        $stmt = $this->prepare($this->insertSql(true));
        $stmt->execute($this->insertParams($row));
        $saved = $this->findByOrderItem($row['amazon_order_id'], $row['amazon_order_item_id']);
        $id = (int)($saved['id'] ?? 0);
        if ($id < 1) throw new RuntimeException('Scoped case upsert could not be resolved.');
        return $id;
    }

    /** @param array<string,mixed> $patch */
    public function update(int $caseId, array $patch): void
    {
        $caseId = $this->positiveId($caseId, 'case ID');
        if ($patch === []) throw new InvalidArgumentException('Case patch cannot be empty.');
        foreach (array_keys($patch) as $field) {
            if (!in_array($field, self::PATCHABLE, true)) {
                throw new InvalidArgumentException('Case patch field is not allowed: ' . $field);
            }
        }
        $this->assertOwned($caseId);

        $sets = [];
        $params = $this->scopeParams([':id'=>$caseId]);
        foreach ($patch as $field=>$value) {
            $parameter = ':patch_' . $field;
            $sets[] = '`' . $field . '`=' . $parameter;
            $params[$parameter] = $this->normalizeField($field, $value);
        }
        $stmt = $this->prepare(
            'UPDATE amazon_return_cases SET ' . implode(',', $sets) . ',updated_at=UTC_TIMESTAMP() '
            . 'WHERE id=:id AND tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() === 0 && $this->find($caseId) === null) {
            throw new RuntimeException('Owned case disappeared during update.');
        }
    }

    public function assertOwned(int $caseId): void
    {
        $caseId = $this->positiveId($caseId, 'case ID');
        $stmt = $this->prepare(
            'SELECT id FROM amazon_return_cases WHERE id=:id AND tenant_id=:tenant_id '
            . 'AND amazon_connection_id=:amazon_connection_id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute($this->scopeParams([':id'=>$caseId]));
        if (!is_array($stmt->fetch(PDO::FETCH_ASSOC))) {
            throw new RuntimeException('Amazon return case is not owned by the current tenant connection.');
        }
    }
    /** @param array<string,mixed> $values @return array<string,mixed> */
    private function normalizeInsert(array $values): array
    {
        foreach (array_keys($values) as $field) {
            if (!in_array($field, self::INSERTABLE, true)) {
                throw new InvalidArgumentException('Case insert field is not allowed: ' . $field);
            }
        }
        foreach (['amazon_order_id','amazon_order_item_id','marketplace_id','state'] as $required) {
            if (!array_key_exists($required, $values)) {
                throw new InvalidArgumentException('Case insert requires ' . $required . '.');
            }
        }
        $row = array_replace([
            'sku'=>null,'asin'=>null,'quantity_ordered'=>1,'quantity_refunded'=>0,'quantity_received'=>0,
            'program'=>'UNKNOWN','refund_initiator'=>'UNKNOWN','refund_at'=>null,'seller_debit_at'=>null,
            'refund_amount'=>'0.00','expected_reimbursement_amount'=>'0.00','reconciled_credit_amount'=>'0.00',
            'physical_status'=>'NOT_RECEIVED','policy_version_id'=>null,'eligibility_at'=>null,'next_action_at'=>null,
            'safe_t_id'=>null,'support_case_id'=>null,'repeated_denial_count'=>0,'last_denial_fingerprint'=>null,
            'appeal_deadline_at'=>null,'terminal_reason'=>null,'closed_at'=>null,
        ], $values);
        foreach ($row as $field=>$value) $row[$field] = $this->normalizeField($field, $value);
        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function insertParams(array $row): array
    {
        $params = $this->scopeParams();
        foreach (self::INSERTABLE as $field) $params[':' . $field] = $row[$field];
        return $params;
    }

    private function insertSql(bool $upsert): string
    {
        $columns = array_merge(['tenant_id','amazon_connection_id'], self::INSERTABLE);
        $quoted = array_map(static fn(string $field): string=>'`' . $field . '`', $columns);
        $parameters = array_map(static fn(string $field): string=>':' . $field, $columns);
        $sql = 'INSERT INTO amazon_return_cases (' . implode(',', $quoted) . ',created_at,updated_at) '
            . 'VALUES (' . implode(',', $parameters) . ',UTC_TIMESTAMP(),UTC_TIMESTAMP())';
        if (!$upsert) return $sql;
        return $sql . ' ON DUPLICATE KEY UPDATE '
            . 'marketplace_id=VALUES(marketplace_id),'
            . 'sku=COALESCE(VALUES(sku),sku),asin=COALESCE(VALUES(asin),asin),'
            . 'quantity_ordered=VALUES(quantity_ordered),'
            . "program=CASE WHEN VALUES(program)<>'UNKNOWN' THEN VALUES(program) ELSE program END,"
            . 'updated_at=UTC_TIMESTAMP()';
    }

    private function normalizeField(string $field, mixed $value): mixed
    {
        return match ($field) {
            'amazon_order_id' => $this->requiredText($value, 'Amazon order ID', 32),
            'amazon_order_item_id' => $this->requiredText($value, 'Amazon order item ID', 64),
            'marketplace_id' => $this->requiredText($value, 'Marketplace ID', 32),
            'sku' => $this->nullableText($value, 'SKU', 128),
            'asin' => $this->nullableText($value, 'ASIN', 32),
            'quantity_ordered' => $this->nonNegativeInt($value, $field, true),
            'quantity_refunded','quantity_received','repeated_denial_count' => $this->nonNegativeInt($value, $field),
            'policy_version_id' => $this->nullablePositiveInt($value, $field),
            'refund_amount','expected_reimbursement_amount','reconciled_credit_amount' => $this->money($value, $field),
            'refund_at','seller_debit_at','eligibility_at','next_action_at','appeal_deadline_at','closed_at' => $this->nullableDate($value, $field),
            'program' => $this->requiredText($value, 'Program', 64),
            'refund_initiator' => $this->requiredText($value, 'Refund initiator', 40),
            'physical_status' => $this->requiredText($value, 'Physical status', 48),
            'state' => $this->requiredText($value, 'State', 64),
            'safe_t_id','support_case_id' => $this->nullableText($value, $field, 64),
            'last_denial_fingerprint' => $this->nullableSha256($value, $field),
            'terminal_reason' => $this->nullableText($value, 'Terminal reason', 128),
            default => throw new InvalidArgumentException('Unsupported case field: ' . $field),
        };
    }
    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function scopeParams(array $extra = []): array
    {
        return $extra + [
            ':tenant_id'=>$this->context->tenantId(),
            ':amazon_connection_id'=>$this->context->amazonConnectionId(),
        ];
    }

    private function prepare(string $sql): PDOStatement
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt instanceof PDOStatement) throw new RuntimeException('Could not prepare scoped case statement.');
        return $stmt;
    }

    private function positiveId(int $value, string $label): int
    {
        if ($value < 1) throw new InvalidArgumentException($label . ' must be positive.');
        return $value;
    }

    private function requiredText(mixed $value, string $label, int $maxLength): string
    {
        if (!is_scalar($value)) throw new InvalidArgumentException($label . ' must be text.');
        $text = trim((string)$value);
        if ($text === '' || strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . ' must contain 1-' . $maxLength . ' bytes.');
        }
        return $text;
    }

    private function nullableText(mixed $value, string $label, int $maxLength): ?string
    {
        if ($value === null || $value === '') return null;
        return $this->requiredText($value, $label, $maxLength);
    }
    private function nonNegativeInt(mixed $value, string $label, bool $positive = false): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        $minimum = $positive ? 1 : 0;
        if ($parsed === false || $parsed < $minimum) {
            throw new InvalidArgumentException($label . ' must be an integer >= ' . $minimum . '.');
        }
        return $parsed;
    }

    private function nullablePositiveInt(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false || $parsed < 1) throw new InvalidArgumentException($label . ' must be positive or null.');
        return $parsed;
    }

    private function money(mixed $value, string $label): string
    {
        if (!is_numeric($value) || (float)$value < 0) {
            throw new InvalidArgumentException($label . ' must be a non-negative amount.');
        }
        return number_format((float)$value, 2, '.', '');
    }

    private function nullableDate(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') return null;
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if (!is_string($value)) throw new InvalidArgumentException($label . ' must be a UTC date or null.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException($label . ' must use Y-m-d H:i:s UTC format.');
        }
        return $value;
    }
    private function nullableSha256(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            throw new InvalidArgumentException($label . ' must be a SHA-256 digest or null.');
        }
        return strtolower($value);
    }
}
