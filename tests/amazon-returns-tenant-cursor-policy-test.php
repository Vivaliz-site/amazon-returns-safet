<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantContext.php';
require_once __DIR__ . '/../includes/amazon-returns/SourceCursorStore.php';
require_once __DIR__ . '/../includes/amazon-returns/PolicyRepository.php';
require_once __DIR__ . '/../includes/amazon-returns/PolicySeeder.php';

function cpSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true)
            . '\nActual: ' . var_export($actual, true));
    }
}

function cpAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class CursorPolicyMemoryPdo extends PDO
{
    /** @var array<string,array<string,mixed>> */
    public array $cursors = [];
    /** @var array<string,array<string,mixed>> */
    public array $policies = [];
    /** @var list<string> */
    public array $prepared = [];
    public int $lastId = 0;

    private bool $transaction = false;
    public function __construct() {}
    public function beginTransaction(): bool { $this->transaction=true;return true; }
    public function commit(): bool { $this->transaction=false;return true; }
    public function rollBack(): bool { $this->transaction=false;return true; }
    public function inTransaction(): bool { return $this->transaction; }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepared[] = $query;
        return new CursorPolicyMemoryStatement($this, $query);
    }
    public function lastInsertId(?string $name=null): string|false
    {
        return (string)$this->lastId;
    }
}

final class CursorPolicyMemoryStatement extends PDOStatement
{
    /** @var list<array<string,mixed>> */
    private array $result = [];

    public function __construct(
        private CursorPolicyMemoryPdo $db,
        private string $sql
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $this->result = [];
        $upper = strtoupper(preg_replace('/\s+/', ' ', trim($this->sql)) ?? trim($this->sql));

        if(str_starts_with($upper,'SELECT SLUG FROM AMAZON_RETURN_TENANTS')) {
            $this->result=[['slug'=>(int)$params[':tenant_id']===1?'shopvivaliz':'other-seller']];return true;
        }
        if(str_starts_with($upper,'SELECT POLICY_KEY,MARKETPLACE_ID')) {
            foreach($this->db->policies as $row){
                if($row['tenant_id']===(int)$params[':tenant_id'] && $row['policy_key']===$params[':policy_key'] && $row['marketplace_id']===$params[':marketplace_id'] && $row['program']===$params[':program'] && $row['effective_from']===$params[':effective_from'])$this->result[]=$row;
            }
            return true;
        }
        if(str_starts_with($upper,'UPDATE AMAZON_RETURN_POLICIES SET STATUS')) {
            foreach($this->db->policies as &$row){
                if($row['tenant_id']!==(int)$params[':tenant_id'])continue;
                if(isset($params[':legacy_key']) && $row['policy_key']===$params[':legacy_key'] && $row['eligibility_days']===75 && $row['marketplace_id']===$params[':marketplace_id'] && $row['program']===$params[':program'])$row['status']='SUPERSEDED';
            }
            unset($row);return true;
        }
        if (str_starts_with($upper, 'INSERT INTO AMAZON_RETURN_SOURCE_CURSORS')) {
            $key = $this->cursorKey($params);
            $this->db->cursors[$key] = [
                'tenant_id'=>(int)$params[':tenant_id'],
                'amazon_connection_id'=>(int)$params[':amazon_connection_id'],
                'source'=>(string)$params[':source'],
                'cursor_key'=>(string)$params[':cursor_key'],
                'cursor_value'=>(string)$params[':cursor_value'],
                'metadata_json'=>$params[':metadata_json'],
                'observed_at'=>'2026-09-04 18:00:00',
            ];
            return true;
        }
        if (str_starts_with($upper, 'SELECT CURSOR_VALUE,METADATA_JSON,OBSERVED_AT FROM AMAZON_RETURN_SOURCE_CURSORS')) {
            $row = $this->db->cursors[$this->cursorKey($params)] ?? null;
            if (is_array($row)) {
                $this->result[] = [
                    'cursor_value'=>$row['cursor_value'],
                    'metadata_json'=>$row['metadata_json'],
                    'observed_at'=>$row['observed_at'],
                ];
            }
            return true;
        }
        if (str_starts_with($upper, 'DELETE FROM AMAZON_RETURN_SOURCE_CURSORS')) {
            unset($this->db->cursors[$this->cursorKey($params)]);
            return true;
        }

        if (str_starts_with($upper, 'INSERT INTO AMAZON_RETURN_POLICIES')) {
            $key = implode('|', [
                $params[':tenant_id'], $params[':policy_key'], $params[':marketplace_id'],
                $params[':program'], $params[':effective_from'],
            ]);
            $this->db->policies[$key] = [
                'id'=>++$this->db->lastId,
                'tenant_id'=>(int)$params[':tenant_id'],
                'policy_key'=>(string)$params[':policy_key'],
                'marketplace_id'=>(string)$params[':marketplace_id'],
                'program'=>(string)$params[':program'],
                'effective_from'=>(string)$params[':effective_from'],
                'effective_to'=>$params[':effective_to'],
                'eligibility_days'=>(int)$params[':eligibility_days'],
                'basis'=>(string)$params[':basis'],
                'source_url'=>(string)$params[':source_url'],
                'source_hash'=>(string)$params[':source_hash'],
                'status'=>(string)$params[':status'],
                'created_at'=>'2026-09-04 18:00:00',
            ];
            return true;
        }
        if (str_starts_with($upper, 'SELECT ID,TENANT_ID,POLICY_KEY')) {
            foreach ($this->db->policies as $row) {
                if ($row['tenant_id'] !== (int)$params[':tenant_id']) continue;
                if ($row['marketplace_id'] !== (string)$params[':marketplace_id']) continue;
                if ($row['program'] !== (string)$params[':program']) continue;
                if ($row['status'] !== 'ACTIVE') continue;
                $this->result[] = $row;
            }
            usort($this->result, static fn(array $a, array $b): int =>
                [$b['effective_from'], $b['id']] <=> [$a['effective_from'], $a['id']]
            );
            return true;
        }

        throw new LogicException('Unexpected cursor/policy SQL: ' . $this->sql);
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return array_shift($this->result) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->result;
        $this->result = [];
        return $rows;
    }

    /** @param array<string,mixed> $params */
    private function cursorKey(array $params): string
    {
        return implode('|', [
            $params[':tenant_id'],
            $params[':amazon_connection_id'],
            $params[':source'],
            $params[':cursor_key'],
        ]);
    }
}

$db = new CursorPolicyMemoryPdo();
$tenant1 = new SvAmazonTenantContext(1, 10);
$tenant2 = new SvAmazonTenantContext(2, 20);
$cursors1 = new SvAmazonSourceCursorStore($db, $tenant1);
$cursors2 = new SvAmazonSourceCursorStore($db, $tenant2);

$cursors1->save('gmail', 'History_ID', '111', ['message_count'=>4]);
$cursors2->save('GMAIL', 'history_id', '222', ['message_count'=>9]);
cpSame('111', $cursors1->load('GMAIL', 'history_id')['value'] ?? null, 'Tenant 1 cursor leaked.');
cpSame(4, $cursors1->load('gmail', 'HISTORY_ID')['metadata']['message_count'] ?? null, 'Cursor metadata must decode.');
cpSame('222', $cursors2->load('GMAIL', 'history_id')['value'] ?? null, 'Tenant 2 cursor leaked.');
$cursors1->clear('gmail', 'history_id');
cpSame(null, $cursors1->load('GMAIL', 'history_id'), 'Tenant 1 cursor must clear locally.');
cpSame('222', $cursors2->load('GMAIL', 'history_id')['value'] ?? null, 'Tenant 1 clear affected tenant 2.');

foreach ([
    ['G MAIL','history_id'],
    ['GMAIL','history id'],
    ['','history_id'],
] as [$source, $key]) {
    $thrown = false;
    try { $cursors1->save($source, $key, '1'); } catch (InvalidArgumentException) { $thrown = true; }
    cpAssert($thrown, 'Invalid cursor identity was accepted.');
}
$thrown = false;
try { $cursors1->save('GMAIL', 'history_id', '333', ['nested'=>['refreshToken'=>'secret']]); }
catch (InvalidArgumentException) { $thrown = true; }
cpAssert($thrown, 'Cursor metadata accepted a secret field.');

$policies1 = new SvAmazonReturnPolicyRepository($db, $tenant1);
$policies2 = new SvAmazonReturnPolicyRepository($db, $tenant2);
$basePolicy = [
    'policy_key'=>'RETURN_NOT_RECEIVED',
    'marketplace_id'=>'A2Q3Y263D00KWC',
    'program'=>'STANDARD',
    'effective_from'=>'2026-01-01',
    'effective_to'=>null,
    'eligibility_days'=>75,
    'basis'=>'SELLER_DEBIT_AT',
    'source_url'=>'https://example.test/policy',
    'source_hash'=>hash('sha256', 'policy-v1'),
    'status'=>'ACTIVE',
];
cpSame(1, $policies1->seed([$basePolicy]), 'Tenant 1 policy seed count.');
cpSame(1, $policies2->seed([array_replace($basePolicy, ['eligibility_days'=>90])]), 'Tenant 2 policy seed count.');
cpSame(75, $policies1->activeFor('A2Q3Y263D00KWC', 'STANDARD')[0]['eligibility_days'] ?? null, 'Tenant 1 policy leaked.');
cpSame(90, $policies2->activeFor('A2Q3Y263D00KWC', 'STANDARD')[0]['eligibility_days'] ?? null, 'Tenant 2 policy leaked.');

$newer = array_replace($basePolicy, [
    'effective_from'=>'2026-04-21',
    'source_hash'=>hash('sha256', 'policy-v2'),
]);
$policies1->seed([$newer]);
cpSame('2026-04-21', $policies1->activeFor('A2Q3Y263D00KWC', 'STANDARD')[0]['effective_from'] ?? null, 'Policies must be newest first.');

$thrown = false;
try { $policies1->seed([$basePolicy + ['tenant_id'=>2]]); }
catch (InvalidArgumentException) { $thrown = true; }
cpAssert($thrown, 'Policy repository accepted caller-supplied ownership.');

cpSame(3, SvAmazonReturnPolicySeeder::ensure($policies1), 'Policy seeder must delegate all approved definitions.');
$beforeRepeat=count($db->policies);
cpSame(3,SvAmazonReturnPolicySeeder::ensure($policies1),'Repeated bootstrap must preserve policy versions.');
cpSame($beforeRepeat,count($db->policies),'Repeated bootstrap must not duplicate policies.');
$legacy=array_values(array_filter($db->policies,static fn(array $r):bool=>$r['tenant_id']===1 && $r['policy_key']==='RETURN_NOT_RECEIVED'));
cpSame(75,$legacy[0]['eligibility_days'],'Superseded policy value must not be rewritten.');
cpSame('SUPERSEDED',$legacy[0]['status'],'Only legacy policy activation should change.');
cpSame(0,SvAmazonReturnPolicySeeder::ensure($policies2),'Do not apply ShopVivaliz operational overrides to other sellers.');
cpSame(90,$policies2->activeFor('A2Q3Y263D00KWC','STANDARD')[0]['eligibility_days'],'Other tenant policy was changed.');
$mutated=SvAmazonReturnPolicySeeder::definitions();$mutated[0]['eligibility_days']=46;
$immutableRejected=false;
try{$policies1->activateOperationalPolicies($mutated,'RETURN_NOT_RECEIVED');}catch(RuntimeException){$immutableRejected=true;}
cpAssert($immutableRejected,'An existing operational version must reject changed metadata.');

$candidate=array_replace($basePolicy,[
    'effective_from'=>'2026-10-01','source_hash'=>hash('sha256','candidate-v1'),'status'=>'CANDIDATE',
]);
$expectedCandidateId=$db->lastId+1;
cpSame($expectedCandidateId,$policies1->insertCandidate($candidate),'Candidate insert must return the tenant-local row ID.');
foreach (SvAmazonReturnPolicySeeder::definitions() as $definition) {
    cpSame(45, $definition['eligibility_days'], 'Current operational policy must be D+45.');
}

foreach ($db->prepared as $sql) {
    if (str_contains($sql, 'amazon_return_source_cursors')) {
        cpAssert(str_contains($sql, 'tenant_id'), 'Cursor SQL is missing tenant scope.');
        cpAssert(str_contains($sql, 'amazon_connection_id'), 'Cursor SQL is missing connection scope.');
    }
    if (str_contains($sql, 'amazon_return_policies')) {
        cpAssert(str_contains($sql, 'tenant_id'), 'Policy SQL is missing tenant scope.');
    }
}

cpSame(2, count(array_filter(
    $db->policies,
    static fn(array $row): bool => $row['policy_key'] === 'RETURN_NOT_RECEIVED'
        && $row['effective_from'] === '2026-01-01'
        && $row['program'] === 'STANDARD'
)), 'Identical policy identities must coexist across tenants.');

echo "amazon-returns-tenant-cursor-policy-test: OK\n";
