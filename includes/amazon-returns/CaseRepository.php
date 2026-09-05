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

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $exposure='(CASE WHEN expected_reimbursement_amount>0 THEN expected_reimbursement_amount ELSE refund_amount END-reconciled_credit_amount)';
        $stmt=$this->prepare("SELECT COUNT(*) total_cases,"
            ."COALESCE(SUM(GREATEST($exposure,0)),0) at_risk,"
            ."COALESCE(SUM(CASE WHEN state IN ('SAFE_T_ELIGIBLE','SAFE_T_READY') THEN GREATEST($exposure,0) ELSE 0 END),0) eligible_now,"
            ."COALESCE(SUM(CASE WHEN state='SAFE_T_SUBMITTED' THEN GREATEST($exposure,0) ELSE 0 END),0) safe_t_submitted,"
            ."COALESCE(SUM(CASE WHEN state='SAFE_T_DENIED' THEN GREATEST($exposure,0) ELSE 0 END),0) denied,"
            ."COALESCE(SUM(CASE WHEN state IN ('APPEAL_REQUIRED','APPEAL_SUBMITTED') THEN GREATEST($exposure,0) ELSE 0 END),0) appeal,"
            ."COALESCE(SUM(CASE WHEN state='SUPPORT_ESCALATION' THEN GREATEST($exposure,0) ELSE 0 END),0) support,"
            ."COALESCE(SUM(CASE WHEN state IN ('SAFE_T_APPROVED','APPEAL_APPROVED','CREDIT_PENDING') THEN GREATEST($exposure,0) ELSE 0 END),0) approved_awaiting_credit,"
            ."COALESCE(SUM(CASE WHEN state='RECOVERED' THEN reconciled_credit_amount ELSE 0 END),0) recovered,"
            ."COALESCE(SUM(CASE WHEN state='CLOSED_LOSS' THEN GREATEST($exposure,0) ELSE 0 END),0) loss,"
            ."SUM(CASE WHEN program='UNKNOWN' THEN 1 WHEN program IN ('STANDARD','FBA_ONSITE','DELIVERY_BY_AMAZON') AND (refund_initiator='UNKNOWN' OR seller_debit_at IS NULL) THEN 1 ELSE 0 END) unclassified,"
            ."SUM(CASE WHEN state IN ('SAFE_T_ELIGIBLE','SAFE_T_READY') AND safe_t_id IS NULL THEN 1 ELSE 0 END) eligible_without_action,"
            ."SUM(CASE WHEN appeal_deadline_at IS NOT NULL AND appeal_deadline_at<UTC_TIMESTAMP() AND state IN ('SAFE_T_DENIED','SAFE_T_INFO_REQUESTED','APPEAL_REQUIRED') THEN 1 ELSE 0 END) expired_without_treatment,"
            ."SUM(CASE WHEN reconciled_credit_amount>0 AND state NOT IN ('RECOVERED','CREDIT_PENDING') THEN 1 ELSE 0 END) credit_without_reconciliation "
            ."FROM amazon_return_cases WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id"
        );
        $stmt->execute($this->scopeParams());
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:[];
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
