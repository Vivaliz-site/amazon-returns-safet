<?php
declare(strict_types=1);

require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/TenantContext.php';

final class SvAmazonTenantMigration
{
    private const LOCK_NAME = 'amazon-returns-tenant-migration';

    /** @var array<string,list<string>> */
    private const OWNERSHIP = [
        'amazon_return_cases'=>['tenant_id','amazon_connection_id'],
        'amazon_return_events'=>['tenant_id','amazon_connection_id'],
        'amazon_return_policies'=>['tenant_id'],
        'amazon_return_evidence'=>['tenant_id','amazon_connection_id'],
        'amazon_return_outbox'=>['tenant_id','amazon_connection_id'],
        'amazon_return_dead_letters'=>['tenant_id','amazon_connection_id'],
        'amazon_return_source_cursors'=>['tenant_id','amazon_connection_id'],
        'amazon_return_overrides'=>['tenant_id','amazon_connection_id'],
    ];

    /** @var array<string,array<string,array{unique:bool,columns:list<string>}>> */
    private const INDEXES = [
        'amazon_return_cases'=>[
            'uq_amazon_return_case_order_item'=>['unique'=>true,'columns'=>['tenant_id','amazon_connection_id','amazon_order_id','amazon_order_item_id']],
            'idx_amazon_return_cases_state_action'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','state','next_action_at']],
            'idx_amazon_return_cases_safe_t'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','safe_t_id']],
            'idx_amazon_return_cases_support_case'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','support_case_id']],
            'idx_amazon_return_cases_eligibility'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','eligibility_at']],
            'idx_amazon_return_cases_seller_debit'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','seller_debit_at']],
        ],
        'amazon_return_events'=>[
            'uq_amazon_return_events_idempotency'=>['unique'=>true,'columns'=>['tenant_id','amazon_connection_id','idempotency_key']],
            'idx_amazon_return_events_case_time'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','case_id','occurred_at','id']],
        ],
        'amazon_return_policies'=>[
            'uq_amazon_return_policy_version'=>['unique'=>true,'columns'=>['tenant_id','policy_key','marketplace_id','program','effective_from']],
            'idx_amazon_return_policies_active'=>['unique'=>false,'columns'=>['tenant_id','marketplace_id','program','status','effective_from']],
        ],
        'amazon_return_evidence'=>[
            'uq_amazon_return_evidence_content'=>['unique'=>true,'columns'=>['tenant_id','amazon_connection_id','case_id','kind','content_sha256']],
            'idx_amazon_return_evidence_case'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','case_id','captured_at','id']],
        ],
        'amazon_return_outbox'=>[
            'uq_amazon_return_outbox_idempotency'=>['unique'=>true,'columns'=>['tenant_id','amazon_connection_id','idempotency_key']],
            'idx_amazon_return_outbox_available'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','status','available_at']],
            'idx_amazon_return_outbox_case_kind'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','case_id','kind']],
        ],
        'amazon_return_dead_letters'=>[
            'uq_amazon_return_dead_letter_outbox'=>['unique'=>true,'columns'=>['tenant_id','amazon_connection_id','outbox_id']],
            'idx_amazon_return_dead_letters_case_kind'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','case_id','kind']],
        ],
        'amazon_return_source_cursors'=>[
            'uq_amazon_return_source_cursor'=>['unique'=>true,'columns'=>['tenant_id','amazon_connection_id','source','cursor_key']],
            'idx_amazon_return_source_cursors_observed'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','source','observed_at']],
        ],
        'amazon_return_overrides'=>[
            'idx_amazon_return_overrides_case_time'=>['unique'=>false,'columns'=>['tenant_id','amazon_connection_id','case_id','created_at','id']],
        ],
    ];

    /** @return array<string,mixed> */
    public static function preview(PDO $db, array $identity): array
    {
        $normalized = self::normalizeIdentity($identity);
        $counts = [];
        foreach (array_keys(self::OWNERSHIP) as $table) {
            $counts[$table] = self::tableExists($db, $table) ? self::count($db, "SELECT COUNT(*) FROM `{$table}`") : 0;
        }
        return [
            'identity'=>[
                'tenant_slug'=>$normalized['tenant_slug'],
                'tenant_name'=>$normalized['tenant_name'],
                'connection_key'=>$normalized['connection_key'],
                'connection_label'=>$normalized['connection_label'],
                'region'=>$normalized['region'],
                'marketplace_id'=>$normalized['marketplace_id'],
                'selling_partner_id_present'=>$normalized['selling_partner_id'] !== null,
            ],
            'tables'=>$counts,
            'would_apply'=>true,
        ];
    }

    public static function migrate(PDO $db, array $identity): SvAmazonTenantContext
    {
        $normalized = self::normalizeIdentity($identity);
        $lock = self::count($db, "SELECT GET_LOCK('amazon-returns-tenant-migration',30)");
        if ($lock !== 1) throw new RuntimeException('Could not acquire tenant migration lock.');

        try {
            SvAmazonReturnsSchema::ensure($db);
            $context = self::resolveContext($db, $normalized);
            self::addOwnershipColumns($db);
            self::backfillOwnership($db, $context);
            $before = self::verify($db, $context);
            if (!$before['valid']) throw new RuntimeException('Tenant ownership verification failed before constraint enforcement.');
            self::enforceOwnershipConstraints($db);
            self::ensureIndexes($db);
            $after = self::verify($db, $context);
            if (!$after['valid']) throw new RuntimeException('Tenant ownership verification failed after constraint enforcement.');
            return $context;
        } finally {
            try { $db->query("SELECT RELEASE_LOCK('amazon-returns-tenant-migration')"); } catch (Throwable) {}
        }
    }

    /** @return array<string,mixed> */
    public static function verify(PDO $db, SvAmazonTenantContext $context): array
    {
        $tables = [];
        $scoped = [];
        $ownershipNulls = 0;
        $scopeMismatches = 0;
        foreach (self::OWNERSHIP as $table=>$columns) {
            $tables[$table] = self::count($db, "SELECT COUNT(*) FROM `{$table}`");
            $conditions = ['`tenant_id`=:tenant_id'];
            $params = [':tenant_id'=>$context->tenantId()];
            if (in_array('amazon_connection_id', $columns, true)) {
                $conditions[] = '`amazon_connection_id`=:connection_id';
                $params[':connection_id'] = $context->amazonConnectionId();
            }
            $scoped[$table] = self::preparedCount($db, "SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' AND ', $conditions), $params);
            $nulls = ['`tenant_id` IS NULL'];
            if (in_array('amazon_connection_id', $columns, true)) $nulls[] = '`amazon_connection_id` IS NULL';
            $ownershipNulls += self::count($db, "SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' OR ', $nulls));
            $scopeMismatches += $tables[$table] - $scoped[$table];
        }
        $caseConnectionMismatches = self::preparedCount($db,
            'SELECT COUNT(*) FROM amazon_return_cases c '
            . 'LEFT JOIN amazon_return_connections a ON a.id=c.amazon_connection_id '
            . 'WHERE a.id IS NULL OR a.tenant_id<>c.tenant_id '
            . 'OR c.tenant_id<>:tenant_id OR c.amazon_connection_id<>:connection_id',
            [':tenant_id'=>$context->tenantId(), ':connection_id'=>$context->amazonConnectionId()]
        );

        $crossTenantChildren = 0;
        foreach (['amazon_return_events','amazon_return_evidence','amazon_return_outbox','amazon_return_overrides'] as $table) {
            $crossTenantChildren += self::count($db,
                "SELECT COUNT(*) FROM `{$table}` child "
                . 'LEFT JOIN amazon_return_cases parent ON parent.id=child.case_id '
                . 'WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id '
                . 'OR parent.amazon_connection_id<>child.amazon_connection_id'
            );
        }
        $crossTenantChildren += self::count($db,
            'SELECT COUNT(*) FROM amazon_return_dead_letters child '
            . 'LEFT JOIN amazon_return_outbox parent ON parent.id=child.outbox_id '
            . 'WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id '
            . 'OR parent.amazon_connection_id<>child.amazon_connection_id OR parent.case_id<>child.case_id'
        );
        $crossTenantChildren += self::count($db,
            'SELECT COUNT(*) FROM amazon_return_source_cursors child '
            . 'LEFT JOIN amazon_return_connections parent ON parent.id=child.amazon_connection_id '
            . 'WHERE parent.id IS NULL OR parent.tenant_id<>child.tenant_id'
        );
        $caseCount = $scoped['amazon_return_cases'] ?? 0;
        return [
            'tables'=>$tables,
            'scoped_tables'=>$scoped,
            'ownership_nulls'=>$ownershipNulls,
            'scope_mismatches'=>$scopeMismatches,
            'cross_tenant_children'=>$crossTenantChildren,
            'case_connection_mismatches'=>$caseConnectionMismatches,
            'case_count'=>$caseCount,
            'valid'=>$caseCount > 0
                && $ownershipNulls === 0
                && $scopeMismatches === 0
                && $crossTenantChildren === 0
                && $caseConnectionMismatches === 0,
        ];
    }

    /** @param array<string,mixed> $identity @return array<string,string|null> */
    private static function normalizeIdentity(array $identity): array
    {
        $tenantSlug = self::slug($identity['tenant_slug'] ?? null, 'tenant_slug');
        $tenantName = self::text($identity['tenant_name'] ?? null, 191, 'tenant_name');
        $connectionKey = self::slug($identity['connection_key'] ?? null, 'connection_key');
        $connectionLabel = self::text($identity['connection_label'] ?? null, 191, 'connection_label');
        $region = strtoupper(self::text($identity['region'] ?? null, 16, 'region'));
        if (!in_array($region, ['NA','EU','FE'], true)) throw new InvalidArgumentException('Unsupported Amazon SP-API region.');
        $marketplaceId = strtoupper(self::text($identity['marketplace_id'] ?? null, 32, 'marketplace_id'));
        if (preg_match('/^[A-Z0-9]{10,32}$/', $marketplaceId) !== 1) throw new InvalidArgumentException('Invalid Amazon marketplace ID.');
        $sellingPartnerId = self::nullableText($identity['selling_partner_id'] ?? null, 64, 'selling_partner_id');
        return [
            'tenant_slug'=>$tenantSlug,
            'tenant_name'=>$tenantName,
            'connection_key'=>$connectionKey,
            'connection_label'=>$connectionLabel,
            'selling_partner_id'=>$sellingPartnerId,
            'region'=>$region,
            'endpoint'=>match ($region) {
                'NA'=>'https://sellingpartnerapi-na.amazon.com',
                'EU'=>'https://sellingpartnerapi-eu.amazon.com',
                'FE'=>'https://sellingpartnerapi-fe.amazon.com',
            },
            'marketplace_id'=>$marketplaceId,
        ];
    }

    /** @param array<string,string|null> $identity */
    private static function resolveContext(PDO $db, array $identity): SvAmazonTenantContext
    {
        $tenantId = self::resolveTenant($db, $identity);
        $connectionId = self::resolveConnection($db, $tenantId, $identity);
        return new SvAmazonTenantContext($tenantId, $connectionId);
    }

    /** @param array<string,string|null> $identity */
    private static function resolveTenant(PDO $db, array $identity): int
    {
        $select = $db->prepare('SELECT id,name,status FROM amazon_return_tenants WHERE slug=:slug LIMIT 1');
        self::execute($select, [':slug'=>$identity['tenant_slug']]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            if (trim((string)($row['name'] ?? '')) !== $identity['tenant_name']) {
                throw new RuntimeException('Existing tenant name does not match migration identity.');
            }
            if (strtoupper(trim((string)($row['status'] ?? ''))) !== 'ACTIVE') {
                throw new RuntimeException('Existing tenant is not active.');
            }
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Existing tenant has an invalid ID.');
            return $id;
        }

        $insert = $db->prepare(
            "INSERT INTO amazon_return_tenants (slug,name,status,created_at,updated_at) "
            . "VALUES (:slug,:name,'ACTIVE',UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
        self::execute($insert, [':slug'=>$identity['tenant_slug'], ':name'=>$identity['tenant_name']]);
        $id = (int)$db->lastInsertId();
        if ($id < 1) throw new RuntimeException('Tenant insert did not return an ID.');
        return $id;
    }

    /** @param array<string,string|null> $identity */
    private static function resolveConnection(PDO $db, int $tenantId, array $identity): int
    {
        $select = $db->prepare(
            'SELECT id,label,selling_partner_id,region,endpoint,marketplace_id,status '
            . 'FROM amazon_return_connections WHERE tenant_id=:tenant_id AND connection_key=:connection_key LIMIT 1'
        );
        self::execute($select, [':tenant_id'=>$tenantId, ':connection_key'=>$identity['connection_key']]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            foreach (['label'=>'connection_label','region'=>'region','endpoint'=>'endpoint','marketplace_id'=>'marketplace_id'] as $column=>$key) {
                if (trim((string)($row[$column] ?? '')) !== $identity[$key]) {
                    throw new RuntimeException("Existing Amazon connection {$column} does not match migration identity.");
                }
            }
            if (strtoupper(trim((string)($row['status'] ?? ''))) !== 'ACTIVE') {
                throw new RuntimeException('Existing Amazon connection is not active.');
            }
            $existingPartner = self::nullableText($row['selling_partner_id'] ?? null, 64, 'existing_selling_partner_id');
            if ($existingPartner !== null && $identity['selling_partner_id'] !== null && $existingPartner !== $identity['selling_partner_id']) {
                throw new RuntimeException('Existing selling partner ID does not match migration identity.');
            }
            $id = (int)($row['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Existing Amazon connection has an invalid ID.');
            if ($existingPartner === null && $identity['selling_partner_id'] !== null) {
                $update = $db->prepare(
                    'UPDATE amazon_return_connections SET selling_partner_id=:selling_partner_id,updated_at=UTC_TIMESTAMP() '
                    . 'WHERE id=:id AND tenant_id=:tenant_id AND selling_partner_id IS NULL'
                );
                self::execute($update, [
                    ':selling_partner_id'=>$identity['selling_partner_id'],
                    ':id'=>$id,
                    ':tenant_id'=>$tenantId,
                ]);
                if ($update->rowCount() !== 1) throw new RuntimeException('Could not attach verified selling partner ID.');
            }
            return $id;
        }

        $insert = $db->prepare(
            "INSERT INTO amazon_return_connections "
            . "(tenant_id,connection_key,label,selling_partner_id,region,endpoint,marketplace_id,status,created_at,updated_at) "
            . "VALUES (:tenant_id,:connection_key,:label,:selling_partner_id,:region,:endpoint,:marketplace_id,'ACTIVE',UTC_TIMESTAMP(),UTC_TIMESTAMP())"
        );
        self::execute($insert, [
            ':tenant_id'=>$tenantId,
            ':connection_key'=>$identity['connection_key'],
            ':label'=>$identity['connection_label'],
            ':selling_partner_id'=>$identity['selling_partner_id'],
            ':region'=>$identity['region'],
            ':endpoint'=>$identity['endpoint'],
            ':marketplace_id'=>$identity['marketplace_id'],
        ]);
        $id = (int)$db->lastInsertId();
        if ($id < 1) throw new RuntimeException('Amazon connection insert did not return an ID.');
        return $id;
    }

    private static function addOwnershipColumns(PDO $db): void
    {
        foreach (self::OWNERSHIP as $table=>$columns) {
            foreach ($columns as $column) {
                if (self::columnExists($db, $table, $column)) continue;
                $after = $column === 'tenant_id' ? '`id`' : '`tenant_id`';
                $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` BIGINT UNSIGNED NULL AFTER {$after}");
            }
        }
    }

    private static function backfillOwnership(PDO $db, SvAmazonTenantContext $context): void
    {
        $tenant = $context->tenantId();
        $connection = $context->amazonConnectionId();
        $cases = $db->prepare(
            'UPDATE amazon_return_cases SET tenant_id=COALESCE(tenant_id,:tenant_id),'
            . 'amazon_connection_id=COALESCE(amazon_connection_id,:connection_id),updated_at=updated_at '
            . 'WHERE (tenant_id IS NULL OR amazon_connection_id IS NULL) '
            . 'AND (tenant_id IS NULL OR tenant_id=:tenant_match) '
            . 'AND (amazon_connection_id IS NULL OR amazon_connection_id=:connection_match)'
        );
        self::execute($cases, [
            ':tenant_id'=>$tenant,
            ':connection_id'=>$connection,
            ':tenant_match'=>$tenant,
            ':connection_match'=>$connection,
        ]);

        foreach (['amazon_return_events','amazon_return_evidence','amazon_return_outbox','amazon_return_overrides'] as $table) {
            $preserveUpdatedAt = $table === 'amazon_return_outbox' ? ',child.updated_at=child.updated_at ' : ' ';
            $db->exec(
                "UPDATE `{$table}` child JOIN amazon_return_cases parent ON parent.id=child.case_id "
                . 'SET child.tenant_id=COALESCE(child.tenant_id,parent.tenant_id),'
                . 'child.amazon_connection_id=COALESCE(child.amazon_connection_id,parent.amazon_connection_id)'
                . $preserveUpdatedAt
                . 'WHERE (child.tenant_id IS NULL OR child.amazon_connection_id IS NULL) '
                . 'AND (child.tenant_id IS NULL OR child.tenant_id=parent.tenant_id) '
                . 'AND (child.amazon_connection_id IS NULL OR child.amazon_connection_id=parent.amazon_connection_id)'
            );
        }

        $db->exec(
            'UPDATE amazon_return_dead_letters child '
            . 'JOIN amazon_return_outbox parent ON parent.id=child.outbox_id '
            . 'SET child.tenant_id=COALESCE(child.tenant_id,parent.tenant_id),'
            . 'child.amazon_connection_id=COALESCE(child.amazon_connection_id,parent.amazon_connection_id) '
            . 'WHERE (child.tenant_id IS NULL OR child.amazon_connection_id IS NULL) '
            . 'AND (child.tenant_id IS NULL OR child.tenant_id=parent.tenant_id) '
            . 'AND (child.amazon_connection_id IS NULL OR child.amazon_connection_id=parent.amazon_connection_id)'
        );

        $policies = $db->prepare(
            'UPDATE amazon_return_policies SET tenant_id=:tenant_id WHERE tenant_id IS NULL'
        );
        self::execute($policies, [':tenant_id'=>$tenant]);

        $cursors = $db->prepare(
            'UPDATE amazon_return_source_cursors SET tenant_id=COALESCE(tenant_id,:tenant_id),'
            . 'amazon_connection_id=COALESCE(amazon_connection_id,:connection_id),updated_at=updated_at '
            . 'WHERE (tenant_id IS NULL OR amazon_connection_id IS NULL) '
            . 'AND (tenant_id IS NULL OR tenant_id=:tenant_match) '
            . 'AND (amazon_connection_id IS NULL OR amazon_connection_id=:connection_match)'
        );
        self::execute($cursors, [
            ':tenant_id'=>$tenant,
            ':connection_id'=>$connection,
            ':tenant_match'=>$tenant,
            ':connection_match'=>$connection,
        ]);
    }

    private static function enforceOwnershipConstraints(PDO $db): void
    {
        foreach (self::OWNERSHIP as $table=>$columns) {
            foreach ($columns as $column) {
                if (!self::columnNullable($db, $table, $column)) continue;
                if ($column === 'tenant_id') {
                    $db->exec("ALTER TABLE `{$table}` MODIFY `tenant_id` BIGINT UNSIGNED NOT NULL");
                } else {
                    $db->exec("ALTER TABLE `{$table}` MODIFY `amazon_connection_id` BIGINT UNSIGNED NOT NULL");
                }
            }
        }
    }

    private static function ensureIndexes(PDO $db): void
    {
        foreach (self::INDEXES as $table=>$indexes) {
            foreach ($indexes as $name=>$definition) {
                $current = self::indexDefinition($db, $table, $name);
                if ($current === $definition) continue;
                if ($current !== null) $db->exec("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
                $unique = $definition['unique'] ? 'UNIQUE ' : '';
                $columns = implode(',', array_map(static fn(string $column): string => "`{$column}`", $definition['columns']));
                $db->exec("ALTER TABLE `{$table}` ADD {$unique}KEY `{$name}` ({$columns})");
            }
        }
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema=DATABASE() AND table_name=:table_name'
        );
        self::execute($statement, [':table_name'=>$table]);
        return (int)$statement->fetchColumn() === 1;
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name=:table_name AND column_name=:column_name'
        );
        self::execute($statement, [':table_name'=>$table, ':column_name'=>$column]);
        return (int)$statement->fetchColumn() === 1;
    }

    private static function columnNullable(PDO $db, string $table, string $column): bool
    {
        $statement = $db->prepare(
            'SELECT is_nullable FROM information_schema.columns '
            . 'WHERE table_schema=DATABASE() AND table_name=:table_name AND column_name=:column_name LIMIT 1'
        );
        self::execute($statement, [':table_name'=>$table, ':column_name'=>$column]);
        $value = $statement->fetchColumn();
        if (!is_string($value)) throw new RuntimeException("Ownership column {$table}.{$column} is missing.");
        return strtoupper($value) === 'YES';
    }

    /** @return array{unique:bool,columns:list<string>}|null */
    private static function indexDefinition(PDO $db, string $table, string $name): ?array
    {
        $statement = $db->prepare(
            "SELECT non_unique,column_name,seq_in_index FROM information_schema.statistics "
            . 'WHERE table_schema=DATABASE() AND table_name=:table_name AND index_name=:index_name '
            . 'ORDER BY seq_in_index'
        );
        self::execute($statement, [':table_name'=>$table, ':index_name'=>$name]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) return null;
        $columns = [];
        $nonUnique = null;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $columns[] = (string)($row['column_name'] ?? '');
            $value = (int)($row['non_unique'] ?? 1);
            $nonUnique = $nonUnique === null ? $value : $nonUnique;
            if ($nonUnique !== $value) throw new RuntimeException("Index {$table}.{$name} has inconsistent uniqueness metadata.");
        }
        return ['unique'=>$nonUnique === 0, 'columns'=>$columns];
    }

    private static function count(PDO $db, string $sql): int
    {
        $statement = $db->query($sql);
        if (!$statement instanceof PDOStatement) throw new RuntimeException('Migration count query failed.');
        return (int)$statement->fetchColumn();
    }

    /** @param array<string,int|string|null> $params */
    private static function preparedCount(PDO $db, string $sql, array $params): int
    {
        $statement = $db->prepare($sql);
        self::execute($statement, $params);
        return (int)$statement->fetchColumn();
    }

    /** @param array<string,int|string|null> $params */
    private static function execute(PDOStatement|false $statement, array $params): void
    {
        if (!$statement instanceof PDOStatement || !$statement->execute($params)) {
            throw new RuntimeException('Tenant migration SQL execution failed.');
        }
    }

    private static function slug(mixed $value, string $name): string
    {
        $text = strtolower(self::text($value, 96, $name));
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $text) !== 1) {
            throw new InvalidArgumentException("Invalid {$name}.");
        }
        return $text;
    }

    private static function text(mixed $value, int $max, string $name): string
    {
        if (!is_scalar($value)) throw new InvalidArgumentException("Invalid {$name}.");
        $text = trim((string)$value);
        if ($text === '' || strlen($text) > $max) throw new InvalidArgumentException("Invalid {$name}.");
        return $text;
    }

    private static function nullableText(mixed $value, int $max, string $name): ?string
    {
        if ($value === null || $value === '') return null;
        return self::text($value, $max, $name);
    }
}
