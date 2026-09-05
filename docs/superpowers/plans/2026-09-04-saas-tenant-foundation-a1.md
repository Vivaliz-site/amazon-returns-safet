> Policy correction (2026-09-05): the former global 75-day rule is withdrawn. Use docs/superpowers/specs/2026-09-05-policy-modes-45-60.md; periods and action routes depend on program, order date and evidence.

# SaaS Tenant Foundation A1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the tenant and Amazon-connection isolation primitives, schema migration, and tenant-scoped persistence stores required before onboarding a second seller.

**Architecture:** A shared MySQL schema gains control-plane records and mandatory `tenant_id`/`amazon_connection_id` ownership on every data-plane row. PHP value objects and repositories require a tenant context at construction, so unscoped reads and writes are not expressible through the supported interfaces. The existing ShopVivaliz seller is backfilled as tenant 1 logically, but generated IDs are always resolved from the database rather than hardcoded.

**Tech Stack:** PHP 8.3, PDO MySQL, existing custom PHP test harnesses, Bash deployment verification, Git worktrees.

**Spec:** `docs/superpowers/specs/2026-09-04-amazon-returns-saas-multitenant-design.md`

## Global Constraints

- Keep every external write flag disabled; this tranche changes persistence only.
- Do not deploy, push, merge, or mutate the production database while implementing this plan.
- Preserve all 37 current cases and their events, evidence, outbox, policy, cursor, and override relationships.
- the applicable 45/60-day matrix remains unchanged for the current ShopVivaliz tenant.
- Every tenant-owned unique key must include `tenant_id`; connection-specific keys must also include `amazon_connection_id`.
- No tenant-owned repository method may execute without an explicit `SvAmazonTenantContext`.
- No credential, browser cookie, OAuth token, or MFA value may enter application tables, logs, fixtures, or migration output.
- All schema and migration operations must be idempotent and fail closed on ownership mismatches.

---
### Task 1: Tenant Context and Control-Plane Schema

**Files:**
- Create: `includes/amazon-returns/TenantContext.php`
- Create: `includes/amazon-returns/TenantRegistry.php`
- Modify: `includes/amazon-returns/Schema.php`
- Create: `tests/amazon-returns-tenant-context-test.php`
- Create: `tests/amazon-returns-tenant-schema-test.php`

**Interfaces:**
- Produces: `SvAmazonTenantContext::__construct(int $tenantId, int $amazonConnectionId, ?int $actorId = null)`
- Produces: `tenantId(): int`, `amazonConnectionId(): int`, `actorId(): ?int`, `scopeKey(): string`
- Produces: `SvAmazonTenantRegistry::resolveCurrent(PDO $db, SvAmazonReturnsConfig $config): SvAmazonTenantContext`
- Consumes later: all repositories receive one immutable context in their constructor.

- [ ] **Step 1: Write the failing tenant-context test**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/Config.php';
require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';

function tcSame(mixed $e,mixed $a,string $m):void{if($e!==$a)throw new RuntimeException($m);}
$ctx = new SvAmazonTenantContext(7, 11, 13);
tcSame(7, $ctx->tenantId(), 'Tenant ID must be immutable.');
tcSame(11, $ctx->amazonConnectionId(), 'Connection ID must be immutable.');
tcSame(13, $ctx->actorId(), 'Actor ID must be optional and immutable.');
tcSame('tenant:7|connection:11', $ctx->scopeKey(), 'Scope key must be deterministic.');
foreach ([[0,1], [1,0], [-1,1]] as [$tenant,$connection]) {
    try { new SvAmazonTenantContext($tenant,$connection); throw new RuntimeException('invalid context accepted'); }
    catch (InvalidArgumentException) {}
}
echo "amazon-returns-tenant-context-test: OK\n";
```
- [ ] **Step 2: Write the failing schema assertions**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/Schema.php';
$ddl = implode("\n", SvAmazonReturnsSchema::statements());
foreach (['amazon_return_tenants','amazon_return_tenant_users','amazon_return_connections','amazon_return_feature_flags'] as $table) {
    if (!str_contains($ddl, "CREATE TABLE IF NOT EXISTS `{$table}`")) throw new RuntimeException("missing {$table}");
}
foreach (['amazon_return_cases','amazon_return_events','amazon_return_evidence','amazon_return_outbox','amazon_return_dead_letters','amazon_return_source_cursors','amazon_return_overrides','amazon_return_policies'] as $table) {
    $start = strpos($ddl, "CREATE TABLE IF NOT EXISTS `{$table}`");
    if ($start === false || !str_contains(substr($ddl,$start,1800), '`tenant_id` BIGINT UNSIGNED NOT NULL')) throw new RuntimeException("{$table} missing tenant_id");
}
foreach (['amazon_return_cases','amazon_return_events','amazon_return_evidence','amazon_return_outbox','amazon_return_dead_letters','amazon_return_source_cursors','amazon_return_overrides'] as $table) {
    $start = strpos($ddl, "CREATE TABLE IF NOT EXISTS `{$table}`");
    if ($start === false || !str_contains(substr($ddl,$start,1800), '`amazon_connection_id` BIGINT UNSIGNED NOT NULL')) throw new RuntimeException("{$table} missing connection");
}
if (!str_contains($ddl,'UNIQUE KEY `uq_amazon_return_case_order_item` (`tenant_id`, `amazon_connection_id`, `amazon_order_id`, `amazon_order_item_id`)')) throw new RuntimeException('case key not tenant scoped');
if (!str_contains($ddl,'UNIQUE KEY `uq_amazon_return_events_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`)')) throw new RuntimeException('event key not tenant scoped');
if (!str_contains($ddl,'UNIQUE KEY `uq_amazon_return_outbox_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`)')) throw new RuntimeException('outbox key not tenant scoped');
echo "amazon-returns-tenant-schema-test: OK\n";
```

- [ ] **Step 3: Run both tests and verify RED**

Run: `php tests/amazon-returns-tenant-context-test.php && php tests/amazon-returns-tenant-schema-test.php`

Expected: the context test fails because `TenantContext.php` is missing; the schema test fails because control-plane tables and ownership columns are absent.
- [ ] **Step 4: Implement the immutable context and registry**

```php
<?php
declare(strict_types=1);

final readonly class SvAmazonTenantContext
{
    public function __construct(private int $tenantId, private int $amazonConnectionId, private ?int $actorId = null)
    {
        if ($tenantId < 1 || $amazonConnectionId < 1 || ($actorId !== null && $actorId < 1)) {
            throw new InvalidArgumentException('Tenant context identifiers must be positive integers.');
        }
    }
    public function tenantId(): int { return $this->tenantId; }
    public function amazonConnectionId(): int { return $this->amazonConnectionId; }
    public function actorId(): ?int { return $this->actorId; }
    public function scopeKey(): string { return 'tenant:' . $this->tenantId . '|connection:' . $this->amazonConnectionId; }
    public function withActor(int $actorId): self { return new self($this->tenantId,$this->amazonConnectionId,$actorId); }
}
```

`TenantRegistry.php` must resolve the current tenant by `AMAZON_RETURNS_TENANT_SLUG` and connection by `AMAZON_RETURNS_CONNECTION_KEY`; it must never invent numeric IDs from environment input. Empty, missing, disabled, or cross-tenant connection records raise `RuntimeException`.

- [ ] **Step 5: Add fresh-install control-plane and tenant-owned DDL**

Create control-plane tables with stable numeric primary keys, unique tenant slug, unique `(tenant_id, connection_key)`, role/status constraints represented as bounded strings, and no secret/token columns. Add mandatory ownership columns and tenant-scoped keys to every existing table. Keep one deterministic statement per table.

- [ ] **Step 6: Run tenant tests and existing schema tests**

Run: `php tests/amazon-returns-tenant-context-test.php && php tests/amazon-returns-tenant-schema-test.php && php tests/amazon-returns-domain-test.php`

Expected: PASS with all prior schema assertions updated to the new deterministic table count.

- [ ] **Step 7: Commit**

```bash
git add includes/amazon-returns/TenantContext.php includes/amazon-returns/TenantRegistry.php includes/amazon-returns/Schema.php tests/amazon-returns-tenant-context-test.php tests/amazon-returns-tenant-schema-test.php tests/amazon-returns-domain-test.php
git commit -m "feat: add tenant control plane and context"
```
### Task 2: Idempotent Single-Tenant Backfill Migration

**Files:**
- Create: `includes/amazon-returns/TenantMigration.php`
- Create: `scripts/migrate-single-tenant-to-multitenant.php`
- Create: `tests/amazon-returns-tenant-migration-test.php`
- Modify: `tests/migration-verification-test.php`

**Interfaces:**
- Consumes: `SvAmazonTenantContext`, control-plane schema from Task 1.
- Produces: `SvAmazonTenantMigration::migrate(PDO $db, array $identity): SvAmazonTenantContext`
- Produces: `SvAmazonTenantMigration::verify(PDO $db, SvAmazonTenantContext $context): array`
- `identity` requires `tenant_slug`, `tenant_name`, `connection_key`, `connection_label`, `region`, and `marketplace_id`; `selling_partner_id` is optional until the Sellers/OAuth onboarding phase verifies it.

- [ ] **Step 1: Write the failing migration contract test**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/TenantMigration.php';
$ref = new ReflectionClass(SvAmazonTenantMigration::class);
foreach (['migrate','verify'] as $method) if (!$ref->hasMethod($method)) throw new RuntimeException("missing {$method}");
$source = (string)file_get_contents(__DIR__ . '/../includes/amazon-returns/TenantMigration.php');
foreach (['information_schema.columns','information_schema.statistics','GET_LOCK','RELEASE_LOCK','tenant_id IS NULL','amazon_connection_id IS NULL'] as $needle) {
    if (!str_contains($source,$needle)) throw new RuntimeException("migration missing {$needle}");
}
$script = (string)file_get_contents(__DIR__ . '/../scripts/migrate-single-tenant-to-multitenant.php');
foreach (['--dry-run','--apply','AMAZON_RETURNS_TENANT_SLUG','AMAZON_RETURNS_CONNECTION_KEY','verification'] as $needle) {
    if (!str_contains($script,$needle)) throw new RuntimeException("script missing {$needle}");
}
if (str_contains($script,'AMAZON_LWA_REFRESH_TOKEN')) throw new RuntimeException('migration must not read or print seller credentials');
echo "amazon-returns-tenant-migration-test: OK\n";
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/amazon-returns-tenant-migration-test.php`

Expected: FAIL because the migration class and script do not exist.
- [ ] **Step 3: Implement the guarded migration**

`migrate()` must acquire `GET_LOCK('amazon-returns-tenant-migration',30)`, start from `SvAmazonReturnsSchema::ensure($db)`, and then:

1. upsert the tenant by slug without changing an existing tenant name silently;
2. upsert the Amazon connection under that tenant by connection key;
3. add nullable ownership columns only when absent;
4. backfill `amazon_return_cases` with both resolved IDs;
5. backfill child tables from their parent case or outbox ownership;
6. backfill policies and source cursors directly to the resolved tenant/connection;
7. call `verify()` before making ownership columns `NOT NULL`;
8. replace all legacy global unique indexes with tenant-scoped equivalents;
9. call `verify()` again and release the advisory lock in `finally`.

`verify()` returns exact counts for each table plus `ownership_nulls`, `cross_tenant_children`, `case_count`, and `valid`. It must set `valid=false` for any null owner, child/parent tenant mismatch, case/connection mismatch, or zero current cases.

- [ ] **Step 4: Implement CLI safety modes**

`--dry-run` is the default and prints only table names, row counts, and the resolved non-secret identity labels. `--apply` requires `AMAZON_RETURNS_TENANT_SLUG`, `AMAZON_RETURNS_TENANT_NAME`, `AMAZON_RETURNS_CONNECTION_KEY`, `AMAZON_RETURNS_CONNECTION_LABEL`, `AMAZON_SP_API_REGION`, and `AMAZON_MARKETPLACE_ID`. `AMAZON_SELLING_PARTNER_ID` is accepted when verified but is not invented or required for the current migration. It exits non-zero unless the final verification is valid.

- [ ] **Step 5: Extend migration-verification structural assertions**

Require the production verification script to compare tenant-scoped counts and hashes and to assert zero null owners and zero child/parent ownership mismatches. Keep the applicable 45/60-day matrix and secret-redaction assertions.

- [ ] **Step 6: Run tests**

Run: `php tests/amazon-returns-tenant-migration-test.php && php tests/migration-verification-test.php && php -l includes/amazon-returns/TenantMigration.php && php -l scripts/migrate-single-tenant-to-multitenant.php`

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/amazon-returns/TenantMigration.php scripts/migrate-single-tenant-to-multitenant.php tests/amazon-returns-tenant-migration-test.php tests/migration-verification-test.php
git commit -m "feat: add guarded tenant backfill migration"
```
### Task 3: Tenant-Scoped Case Repository

**Files:**
- Create: `includes/amazon-returns/CaseRepository.php`
- Create: `tests/amazon-returns-tenant-case-repository-test.php`

**Interfaces:**
- Consumes: `PDO`, `SvAmazonTenantContext`.
- Produces: `SvAmazonReturnCaseRepository::__construct(PDO $db, SvAmazonTenantContext $context)`
- Produces: `find(int $caseId): ?array`, `findByOrderItem(string $orderId,string $itemId): ?array`
- Produces: `openCases(int $limit = 250): array`, `caseIdsForOrders(array $orderIds): array`, `countOpen(): int`, `earliestObservedDate(): ?string`
- Produces: `findSingleByOrder(string $orderId): ?array`, returning null when zero or multiple rows exist
- Produces: `upsertOrderItem(array $values): int`, `insert(array $values): int`, `update(int $caseId,array $patch): void`, `assertOwned(int $caseId): void`

- [ ] **Step 1: Write a cross-tenant failing test using an in-memory repository double**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/CaseRepository.php';

final class TenantCaseMemoryPdo extends PDO {
    public array $rows=[]; public array $prepared=[]; public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{
        $this->prepared[]=$query; return new TenantCaseMemoryStatement($this,$query);
    }
}
final class TenantCaseMemoryStatement extends PDOStatement {
    private array $params=[]; private ?array $row=null;
    public function __construct(private TenantCaseMemoryPdo $db,private string $sql){}
    public function execute(?array $params=null):bool{
        $this->params=$params??[]; $this->row=null;
        foreach($this->db->rows as $row) if(($row['id']??0)===($this->params[':id']??-1) && ($row['tenant_id']??0)===($this->params[':tenant_id']??-2)){$this->row=$row;break;}
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return $this->row?:false;}
}
$db=new TenantCaseMemoryPdo();
$db->rows=[['id'=>41,'tenant_id'=>1,'amazon_connection_id'=>10],['id'=>42,'tenant_id'=>2,'amazon_connection_id'=>20]];
$repo=new SvAmazonReturnCaseRepository($db,new SvAmazonTenantContext(1,10));
if(($repo->find(41)['id']??null)!==41)throw new RuntimeException('owned case missing');
if($repo->find(42)!==null)throw new RuntimeException('cross-tenant case leaked');
foreach($db->prepared as $sql) if(!str_contains($sql,'tenant_id')) throw new RuntimeException('unscoped SQL prepared');
echo "amazon-returns-tenant-case-repository-test: OK\n";
```
- [ ] **Step 2: Run the repository test and verify RED**

Run: `php tests/amazon-returns-tenant-case-repository-test.php`

Expected: FAIL because `CaseRepository.php` is missing.

- [ ] **Step 3: Implement a whitelist-based repository**

Every SQL statement must bind both `:tenant_id` and, for connection-owned case operations, `:amazon_connection_id`. `update()` accepts only this exact field whitelist:

```php
private const PATCHABLE = [
    'quantity_refunded','quantity_received','program','refund_initiator','refund_at','seller_debit_at',
    'refund_amount','expected_reimbursement_amount','reconciled_credit_amount','physical_status','state',
    'policy_version_id','eligibility_at','next_action_at','safe_t_id','support_case_id',
    'repeated_denial_count','last_denial_fingerprint','appeal_deadline_at','terminal_reason','closed_at',
];
```

Unknown fields, empty patches, non-positive IDs, and writes affecting a row outside the current scope raise exceptions. `insert()` injects ownership from the context and never accepts ownership fields from callers.

- [ ] **Step 4: Add SQL-shape assertions**

Extend the test to assert that `find`, `findByOrderItem`, `openCases`, `insert`, and `update` all contain `tenant_id`; connection-specific methods must contain `amazon_connection_id`. Assert that attempting to patch `tenant_id` or `amazon_connection_id` throws `InvalidArgumentException`.

- [ ] **Step 5: Run tests and syntax checks**

Run: `php tests/amazon-returns-tenant-case-repository-test.php && php -l includes/amazon-returns/CaseRepository.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/amazon-returns/CaseRepository.php tests/amazon-returns-tenant-case-repository-test.php
git commit -m "feat: add tenant scoped case repository"
```
### Task 4: Tenant-Scoped Event and Evidence Stores

**Files:**
- Create: `includes/amazon-returns/TenantEventStore.php`
- Create: `includes/amazon-returns/EvidenceStore.php`
- Create: `tests/amazon-returns-tenant-event-evidence-test.php`

**Interfaces:**
- Consumes: `PDO`, `SvAmazonTenantContext`, owned case IDs.
- Produces: `new SvAmazonTenantReturnEventStore(PDO $db,SvAmazonTenantContext $context)`
- Produces: `append(array $event): int`, `eventsForCase(int $caseId): array`, static `deterministicKey(string ...$parts): string`
- Produces: `new SvAmazonReturnEvidenceStore(PDO $db,SvAmazonTenantContext $context)`
- Produces: `record(int $caseId,array $evidence): int`, `forCase(int $caseId): array`
- Leaves the legacy static `EventStore.php` untouched until all callers move in Task 7, keeping the full suite green between commits.

- [ ] **Step 1: Write the failing isolation test**

The test seeds event and evidence rows for tenants 1 and 2 with the same case ID and the same SHA/idempotency values. It must assert that tenant 1 reads only tenant 1 rows and that duplicate resolution never returns tenant 2's row. It also scans prepared SQL and fails any statement missing `tenant_id` and `amazon_connection_id` where case ownership is checked.

```php
$context = new SvAmazonTenantContext(1,10);
$events = new SvAmazonTenantReturnEventStore($db,$context);
$first = $events->append($baseEvent);
$duplicate = $events->append(array_replace($baseEvent,['payload'=>['amount'=>'999.99']]));
if ($first !== $duplicate) throw new RuntimeException('tenant-local duplicate was not resolved');
if (count($events->eventsForCase(42)) !== 1) throw new RuntimeException('cross-tenant event leaked');
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/amazon-returns-tenant-event-evidence-test.php`

Expected: FAIL because both tenant stores are missing.

- [ ] **Step 3: Implement TenantEventStore with structural scope**

The constructor stores PDO and context. `append()` injects `tenant_id`, verifies the referenced case with `SELECT id FROM amazon_return_cases WHERE id=:case_id AND tenant_id=:tenant_id AND amazon_connection_id=:connection_id`, inserts the tenant owner, and resolves duplicates with `(tenant_id,amazon_connection_id,idempotency_key)`. `eventsForCase()` uses all three ownership predicates before returning rows.

- [ ] **Step 4: Implement EvidenceStore**

`record()` validates the same owned case, accepts exactly `kind`, `source`, optional `external_id`, `content_sha256`, optional `storage_ref`, `metadata`, and `captured_at`, and inserts tenant ownership from context. Duplicate lookup uses `(tenant_id,amazon_connection_id,case_id,kind,content_sha256)`. `forCase()` filters by tenant, connection, and case.

- [ ] **Step 5: Run focused and full tests**

Run: `php tests/amazon-returns-tenant-event-evidence-test.php && for test in tests/*.php; do php "$test"; done && php -l includes/amazon-returns/TenantEventStore.php && php -l includes/amazon-returns/EvidenceStore.php`

Expected: PASS; legacy callers still use the old store until Task 7.

- [ ] **Step 6: Commit**

```bash
git add includes/amazon-returns/TenantEventStore.php includes/amazon-returns/EvidenceStore.php tests/amazon-returns-tenant-event-evidence-test.php
git commit -m "feat: add tenant scoped event and evidence stores"
```

### Task 5: Tenant-Scoped Outbox, Leasing, and Dead Letters

**Files:**
- Create: `includes/amazon-returns/TenantOutbox.php`
- Create: `tests/amazon-returns-tenant-outbox-test.php`

**Interfaces:**
- Consumes: `PDO`, `SvAmazonTenantContext`.
- Produces: `new SvAmazonTenantReturnsOutbox(PDO $db,SvAmazonTenantContext $context)`
- Produces: `deterministicKey(string $kind,int $caseId,string $scope): string`
- Produces: `enqueue(string $kind,int $caseId,array $payload,string $idempotencyKey): int`
- Produces: `claimBatch(int $limit=10,array $kinds=[]): array`, `findOwned(int $id): ?array`
- Produces: `markSucceeded(int $id): void`, `releaseUnprocessed(int $id): void`
- Produces: `reschedule(int $id,DateTimeImmutable $availableAt,string $error): void`
- Produces: `markFailed(array $row,Throwable|string $error,?DateTimeImmutable $now=null): array`
- Keeps static pure functions: `retryDecision()` and `leaseExpired()`.
- Leaves legacy `Outbox.php` untouched until Task 7.

- [ ] **Step 1: Write a failing lease-isolation test**

Seed due rows for two tenants at identical timestamps. Tenant 1 `claimBatch()` must return only tenant 1 rows, must never update tenant 2, and every claimed row must report the bound owner IDs. Attempting to mark tenant 2's ID from tenant 1 must affect zero rows and raise `RuntimeException`.

```php
$outbox = new SvAmazonTenantReturnsOutbox($db,new SvAmazonTenantContext(1,10));
$key = $outbox->deterministicKey('SAFE_T_SUBMIT',77,'policy-12|2026-07-16');
$first = $outbox->enqueue('SAFE_T_SUBMIT',77,['order_id'=>'702-1234567-7654321'],$key);
$second = $outbox->enqueue('SAFE_T_SUBMIT',77,['order_id'=>'different-payload-ignored-by-key'],$key);
if ($first !== $second) throw new RuntimeException('tenant-local idempotency failed');
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/amazon-returns-tenant-outbox-test.php`

Expected: FAIL because `TenantOutbox.php` is missing.

- [ ] **Step 3: Implement scoped idempotency and leasing**

`deterministicKey()` hashes `context.scopeKey()`, normalized kind, case ID, and scope. `enqueue()` verifies case ownership, injects both owner IDs, and resolves duplicates by `(tenant_id,amazon_connection_id,idempotency_key)`. `claimBatch()` filters by both owner IDs before `FOR UPDATE SKIP LOCKED`; every subsequent update includes both owner predicates.

Dead-letter inserts copy owner IDs from the context, not from worker payload. `markFailed()` rejects a row whose embedded owner does not exactly match the context before SQL. `markSucceeded`, `releaseUnprocessed`, `reschedule`, and terminal updates require `rowCount() === 1` to prevent silent cross-tenant success.

- [ ] **Step 4: Run focused and full tests**

Run: `php tests/amazon-returns-tenant-outbox-test.php && for test in tests/*.php; do php "$test"; done && php -l includes/amazon-returns/TenantOutbox.php`

Expected: PASS; legacy callers remain unchanged until Task 7.

- [ ] **Step 5: Commit**

```bash
git add includes/amazon-returns/TenantOutbox.php tests/amazon-returns-tenant-outbox-test.php
git commit -m "feat: add tenant scoped outbox"
```

### Task 6: Tenant-Scoped Source Cursors and Policies

**Files:**
- Create: `includes/amazon-returns/SourceCursorStore.php`
- Create: `includes/amazon-returns/PolicyRepository.php`
- Modify: `includes/amazon-returns/PolicySeeder.php`
- Create: `tests/amazon-returns-tenant-cursor-policy-test.php`

**Interfaces:**
- Produces: `SvAmazonSourceCursorStore::__construct(PDO $db,SvAmazonTenantContext $context)`
- Produces: `load(string $source,string $key): ?array`, `save(string $source,string $key,string $value,array $metadata=[]): void`, `clear(string $source,string $key): void`
- Produces: `SvAmazonReturnPolicyRepository::__construct(PDO $db,SvAmazonTenantContext $context)`
- Produces: `activeFor(string $marketplaceId,string $program): array`, `seed(array $definitions): int`
- Changes: `SvAmazonReturnPolicySeeder::ensure(PDO|SvAmazonReturnPolicyRepository $target): int` temporarily accepts legacy PDO until Task 8 removes that branch.

- [ ] **Step 1: Write the failing isolation test**

```php
$tenant1 = new SvAmazonTenantContext(1,10);
$tenant2 = new SvAmazonTenantContext(2,20);
$cursors1 = new SvAmazonSourceCursorStore($db,$tenant1);
$cursors2 = new SvAmazonSourceCursorStore($db,$tenant2);
$cursors1->save('GMAIL','history_id','111');
$cursors2->save('GMAIL','history_id','222');
if (($cursors1->load('GMAIL','history_id')['value']??null)!=='111') throw new RuntimeException('tenant 1 cursor leaked');
if (($cursors2->load('GMAIL','history_id')['value']??null)!=='222') throw new RuntimeException('tenant 2 cursor leaked');
```

Add equivalent policy fixtures with the same policy key/effective date for tenants 1 and 2 and assert both versions coexist and return only within their tenant.

- [ ] **Step 2: Run the test and verify RED**

Run: `php tests/amazon-returns-tenant-cursor-policy-test.php`

Expected: FAIL because the stores do not exist.
- [ ] **Step 3: Implement cursor validation and ownership**

Normalize source/key to uppercase source and lowercase `[a-z0-9_:-]` key, each bounded to the schema length. All cursor SQL binds `tenant_id` and `amazon_connection_id`; the unique key is `(tenant_id,amazon_connection_id,source,cursor_key)`. Metadata is JSON encoded with `JSON_THROW_ON_ERROR` and never contains secret fields.

- [ ] **Step 4: Implement the policy repository**

Policy rows are tenant-owned but marketplace/program scoped. `seed()` injects `tenant_id`, uses `(tenant_id,policy_key,marketplace_id,program,effective_from)`, and updates only policy content fields. `activeFor()` returns policies for the bound tenant and requested marketplace/program ordered newest effective date first.

Update the seeder to call:

```php
public static function ensure(PDO|SvAmazonReturnPolicyRepository $target): int
{
    if ($target instanceof SvAmazonReturnPolicyRepository) return $target->seed(self::definitions());
    // Temporary compatibility only; Task 8 deletes this branch after every runtime caller is scoped.
    return self::legacyEnsure($target);
}
```

- [ ] **Step 5: Run tests**

Run: `php tests/amazon-returns-tenant-cursor-policy-test.php && php tests/amazon-returns-policy-test.php && php -l includes/amazon-returns/SourceCursorStore.php && php -l includes/amazon-returns/PolicyRepository.php`

Expected: PASS without changing the applicable 45/60-day matrix definitions.

- [ ] **Step 6: Commit**

```bash
git add includes/amazon-returns/SourceCursorStore.php includes/amazon-returns/PolicyRepository.php includes/amazon-returns/PolicySeeder.php tests/amazon-returns-tenant-cursor-policy-test.php tests/amazon-returns-policy-test.php
git commit -m "refactor: scope cursors and policies by tenant"
```
### Task 7: Tenant Persistence Aggregate and Domain Adapter Migration

**Files:**
- Create: `includes/amazon-returns/TenantPersistence.php`
- Modify: `includes/amazon-returns/GmailEventSink.php`
- Modify: `includes/amazon-returns/SpApiEventSink.php`
- Modify: `includes/amazon-returns/ReturnsReport.php`
- Modify: `includes/amazon-returns/Projector.php`
- Modify: `workers/amazon-returns/gmail-ingest.php`
- Modify: `workers/amazon-returns/scheduler.php`
- Delete after caller migration: `includes/amazon-returns/EventStore.php`
- Delete after caller migration: `includes/amazon-returns/Outbox.php`
- Modify: `tests/amazon-returns-domain-test.php`
- Modify: `tests/amazon-returns-reliability-test.php`
- Modify: focused Gmail, SP-API, report, projector, and scheduler tests

**Interfaces:**
- Produces: `SvAmazonTenantPersistence::create(PDO $db,SvAmazonTenantContext $context): self`
- Exposes readonly `cases`, `events`, `evidence`, `outbox`, `cursors`, and `policies` repositories.
- Changes sinks/report/projector/scheduler to receive the aggregate or a focused repository instead of raw unscoped PDO.

- [ ] **Step 1: Write the aggregate construction test**

```php
$p = SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(1,10));
if (!$p->cases instanceof SvAmazonReturnCaseRepository) throw new RuntimeException('cases repository missing');
if (!$p->events instanceof SvAmazonTenantReturnEventStore) throw new RuntimeException('events repository missing');
if (!$p->outbox instanceof SvAmazonTenantReturnsOutbox) throw new RuntimeException('outbox repository missing');
if (!$p->cursors instanceof SvAmazonSourceCursorStore) throw new RuntimeException('cursor repository missing');
if (!$p->policies instanceof SvAmazonReturnPolicyRepository) throw new RuntimeException('policy repository missing');
```

- [ ] **Step 2: Update adapter tests before production code**

For Gmail, SP-API, returns-report, projector, and scheduler tests, instantiate one context and persistence aggregate. Add a second tenant with the same order ID/cursor/idempotency values and assert only the bound tenant changes.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php tests/amazon-returns-gmail-test.php && php tests/amazon-returns-spapi-test.php && php tests/amazon-returns-report-test.php && php tests/amazon-returns-domain-test.php && php tests/amazon-returns-reliability-test.php`

Expected: FAIL at the new constructor/signature assertions.
- [ ] **Step 4: Implement the aggregate and migrate domain adapters**

Use focused method signatures:

```php
SvAmazonGmailEventSink::persist(SvAmazonTenantPersistence $p,array $event): int;
SvAmazonSpApiEventSink::persist(SvAmazonTenantPersistence $p,array $order,array $transactions): array;
SvAmazonReturnsReport::persistRows(SvAmazonTenantPersistence $p,array $rows,string $documentId,string $evidenceSha256): array;
SvAmazonReturnProjector::project(SvAmazonReturnCaseRepository $cases,SvAmazonTenantReturnEventStore $events,int $caseId): array;
SvAmazonReturnsScheduler::schedule(SvAmazonTenantReturnsOutbox $outbox,array $case,array $timeline,array $policy): array;
```

Move case lookup/upsert/update logic from Gmail/SpApi/ReturnsReport into `CaseRepository`; move event appends to the tenant event store; move cursors to `SourceCursorStore`; move queue writes to the tenant outbox. Convert domain and reliability tests to the new instance APIs. After the final caller is migrated, delete the legacy unscoped `EventStore.php` and `Outbox.php`; do not retain aliases or wrappers that can execute unscoped SQL. Pure parsers and decision engines remain static and tenant-agnostic.

- [ ] **Step 5: Remove domain-level raw SQL for tenant-owned tables**

Run:

```bash
grep -RIn "amazon_return_" includes/amazon-returns workers/amazon-returns/gmail-ingest.php workers/amazon-returns/scheduler.php
```

Expected after this task: raw SQL remains only inside Schema, TenantMigration, the six tenant repositories, and later runtime/API integration files explicitly deferred to Task 8.

- [ ] **Step 6: Run focused and complete tests**

Run: `for test in tests/*.php; do php "$test"; done`

Expected: PASS. Existing external-write behavior remains unchanged and disabled.

- [ ] **Step 7: Commit**

```bash
git add -A includes/amazon-returns/EventStore.php includes/amazon-returns/Outbox.php includes/amazon-returns/TenantPersistence.php includes/amazon-returns/GmailEventSink.php includes/amazon-returns/SpApiEventSink.php includes/amazon-returns/ReturnsReport.php includes/amazon-returns/Projector.php workers/amazon-returns/gmail-ingest.php workers/amazon-returns/scheduler.php tests
git commit -m "refactor: route domain persistence through tenant stores"
```
### Task 8: Runtime, Admin, and Bridge Tenant Binding

**Files:**
- Modify: `includes/amazon-returns/Runtime.php`
- Modify: `workers/amazon-returns/daemon.php`
- Modify: `api/amazon-returns/bridge.php`
- Modify: `api/amazon-returns/status-bridge.php`
- Modify: `admin/amazon-returns/api/case.php`
- Modify: `admin/amazon-returns/api/intake.php`
- Modify: `admin/amazon-returns/api/summary.php`
- Modify: `scripts/shadow-audit.php`
- Modify: focused runtime/admin/bridge/shadow tests

**Interfaces:**
- Changes: `SvAmazonReturnsRuntime::bootstrap(PDO,SvAmazonTenantContext): array`
- Changes: `SvAmazonReturnsRuntime::health(SvAmazonTenantPersistence,SvAmazonReturnsConfig): array`
- Changes: `SvAmazonReturnsDaemon::__construct(PDO,SvAmazonTenantContext,?SvAmazonReturnsConfig=null)`
- Entrypoints resolve context exactly once through `SvAmazonTenantRegistry::resolveCurrent()` and build one persistence aggregate.

- [ ] **Step 1: Write failing runtime and endpoint source-contract assertions**

Require every listed entrypoint to reference `TenantRegistry`, `TenantPersistence`, or receive a repository. Fail if it contains any tenant-owned `SELECT`, `UPDATE`, `INSERT`, or `DELETE` statement without the literal `tenant_id` predicate/column. Require bridge job envelopes to include `tenant_id` and `amazon_connection_id`, but never accept either value as authoritative from an unauthenticated request body.

- [ ] **Step 2: Add daemon tenant-state isolation test**

Construct two daemon instances with tenant 1/connection 10 and tenant 2/connection 20. Assert their runtime-state paths differ by `tenant-1-connection-10` and `tenant-2-connection-20`, and one tenant's due-task timestamps cannot suppress the other tenant's tasks.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php tests/amazon-returns-runtime-test.php && php tests/amazon-returns-admin-test.php && php tests/amazon-returns-remote-bridge-test.php && php tests/shadow-audit-test.php`

Expected: FAIL because runtime and entrypoints are still globally scoped.
- [ ] **Step 4: Bind runtime and daemon to one immutable tenant context**

Resolve context before bootstrap. `Runtime::bootstrap()` ensures schema, then seeds policies through the tenant repository. Health counts cases, pending/processing outbox, dead letters, and cursors only for the bound tenant/connection. State file naming is derived from numeric IDs and rejects a caller-supplied path that lacks the scope suffix in multi-tenant mode.

- [ ] **Step 5: Bind bridge endpoints**

The current bearer token is associated with the environment-resolved current connection. Pull queries and result acknowledgements include owner predicates on every statement. A result for a job outside the bound scope returns `JOB_NOT_FOUND`, not ownership details. Job payload owner fields are generated server-side.

- [ ] **Step 6: Bind admin APIs and shadow audit**

Admin case lookup/intake/summary use the case/event/evidence repositories. Intake cannot write to a case outside the session context. Shadow audit accepts explicit source/target tenant identities and compares only those scopes; its report includes counts but no credentials.

- [ ] **Step 7: Run focused and full tests**

Run:

```bash
php tests/amazon-returns-runtime-test.php
php tests/amazon-returns-admin-test.php
php tests/amazon-returns-remote-bridge-test.php
php tests/amazon-returns-safe-t-status-test.php
php tests/shadow-audit-test.php
for test in tests/*.php; do php "$test"; done
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add includes/amazon-returns/Runtime.php workers/amazon-returns/daemon.php api/amazon-returns admin/amazon-returns/api scripts/shadow-audit.php tests
git commit -m "refactor: bind runtime endpoints to tenant context"
```
### Task 9: Tenant-SQL Audit and Cross-Tenant Integration Gate

**Files:**
- Create: `scripts/audit-tenant-sql.php`
- Create: `tests/amazon-returns-tenant-isolation-test.php`
- Create: `tests/tenant-sql-audit-test.php`
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Produces: CLI exits 0 only when supported application code has no unapproved unscoped tenant-table SQL.
- Produces: a two-tenant integration fixture proving same external identifiers and idempotency values coexist without reads or writes crossing scope.

- [ ] **Step 1: Write the failing audit test**

```php
<?php
declare(strict_types=1);
$script=__DIR__.'/../scripts/audit-tenant-sql.php';
if(!is_file($script))throw new RuntimeException('tenant SQL audit missing');
exec('php '.escapeshellarg($script).' 2>&1',$out,$status);
if($status!==0)throw new RuntimeException("tenant SQL audit failed:\n".implode("\n",$out));
echo "tenant-sql-audit-test: OK\n";
```

The audit allowlist contains only `Schema.php`, `TenantMigration.php`, `TenantRegistry.php`, and the focused repository/store files. For every other PHP file, any SQL string naming a tenant-owned table is a failure. This forces new code through scoped interfaces.

- [ ] **Step 2: Write the two-tenant integration test**

Use a MySQL-compatible test double or disposable test schema to seed:

- tenants 1 and 2;
- connections 10 and 20;
- the same Amazon order ID/item ID in both tenants;
- the same event idempotency digest in both tenants;
- the same cursor source/key in both tenants;
- one due outbox row in both tenants.

Assert each persistence aggregate sees one case, one event, its own cursor value, and claims only its own job. Attempt all cross-tenant case, event, evidence, outbox, and cursor mutations and require an exception or zero visible effect.

- [ ] **Step 3: Run tests and verify RED**

Run: `php tests/amazon-returns-tenant-isolation-test.php && php tests/tenant-sql-audit-test.php`

Expected: FAIL until all application SQL is routed through the scoped stores.
- [ ] **Step 4: Implement the audit and fix every reported violation**

Parse PHP files with `token_get_all()` and inspect complete quoted string literals, not comments. Flag a literal only when it contains a SQL DML keyword (`SELECT`, `FROM`, `JOIN`, `INSERT`, `UPDATE`, or `DELETE`) plus a tenant-owned table name and the file path is not allowlisted. The command prints `path:line:table` only; it must not print SQL parameters or environment values.

- [ ] **Step 5: Add CI gates**

Add these commands after the PHP test loop:

```bash
php scripts/audit-tenant-sql.php
php tests/amazon-returns-tenant-isolation-test.php
```

CI continues to syntax-check every PHP file and both Seller Central workers.

- [ ] **Step 6: Run the isolation and full CI-equivalent suite**

Run:

```bash
set -e
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l
node --check scripts/amazon-returns/safe-t-status-parser.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
bash -n scripts/install-service.sh
```

Expected: every command exits 0.

- [ ] **Step 7: Commit**

```bash
git add scripts/audit-tenant-sql.php tests/amazon-returns-tenant-isolation-test.php tests/tenant-sql-audit-test.php .github/workflows/ci.yml
git commit -m "test: enforce tenant isolation in CI"
```
### Task 10: Migration Runbook and Deployment Safety Gate

**Files:**
- Modify: `scripts/provision-production.sh`
- Modify: `scripts/verify-migration.sh`
- Create: `docs/runbooks/tenant-foundation-migration.md`
- Modify: `tests/production-provision-test.php`
- Modify: `tests/migration-verification-test.php`

**Interfaces:**
- Provisioning writes non-secret tenant identity variables to the isolated runtime environment.
- Migration remains a separately invoked, reversible pre-deploy operation; service restart occurs only after verification.
- No script enables any external write flag.

- [ ] **Step 1: Write failing deployment assertions**

Require provisioning to define `AMAZON_RETURNS_TENANT_SLUG=shopvivaliz`, `AMAZON_RETURNS_CONNECTION_KEY=amazon-br-primary`, `AMAZON_SP_API_REGION=NA`, and invoke the migration in `--dry-run` before any apply instruction. Require all four current write flags to remain `0`. Require verification output files to contain tenant counts, ownership nulls, cross-tenant mismatch count, and pre/post content hashes.

- [ ] **Step 2: Run deployment tests and verify RED**

Run: `php tests/production-provision-test.php && php tests/migration-verification-test.php`

Expected: FAIL because tenant migration gates are not yet represented.

- [ ] **Step 3: Update scripts without performing deployment**

The provisioning script installs new identity variables only when absent, never overwrites an existing tenant identity, and prints the exact operator commands for dry-run, backup, apply, verification, and rollback. It must not automatically apply the ownership migration on a live database.

`verify-migration.sh` exports canonical ordered rows including owner IDs, computes SHA-256 hashes, compares 37 source/target current cases for the ShopVivaliz cutover, checks the applicable 45/60-day matrix, checks zero null owners/mismatches, and redacts environment contents.

- [ ] **Step 4: Write the migration runbook**

Document exact preconditions, backup command, maintenance/freeze gate, dry-run, apply, verification, rollback trigger, service restart, health checks, and the rule that all external writes remain disabled until the later application-integration phase passes production acceptance.

- [ ] **Step 5: Run deployment tests and shell syntax**

Run: `php tests/production-provision-test.php && php tests/migration-verification-test.php && bash -n scripts/provision-production.sh && bash -n scripts/verify-migration.sh`

Expected: PASS.
- [ ] **Step 6: Run final plan verification**

```bash
set -e
for test in tests/*.php; do php "$test"; done
php scripts/audit-tenant-sql.php
find includes api admin workers scripts -name '*.php' -print0 | xargs -0 -n1 php -l
node --check scripts/amazon-returns/safe-t-status-parser.mjs
node --check scripts/amazon-returns/seller-central-safe-t-read-worker.mjs
node --check scripts/amazon-returns/seller-central-bridge-worker.mjs
bash -n scripts/install-service.sh
bash -n scripts/provision-production.sh
bash -n scripts/verify-migration.sh
git diff --check
```

Expected: all commands exit 0; `git status --short` contains only intentional plan outputs or is clean after commit.

- [ ] **Step 7: Commit**

```bash
git add scripts/provision-production.sh scripts/verify-migration.sh docs/runbooks/tenant-foundation-migration.md tests/production-provision-test.php tests/migration-verification-test.php
git commit -m "docs: add tenant migration safety runbook"
```

## Completion Boundary

This plan is complete when tenant ownership is structurally enforced in the schema and supported persistence interfaces, every current application path is bound to one tenant context, cross-tenant tests pass, and deployment scripts are ready for a separately approved migration window.

It does **not** apply the production migration, onboard an outside seller, change authentication/RBAC, store per-seller credentials, enable browser automation for multiple tenants, or enable any external write. Those are covered by subsequent plans derived from the same spec.
