<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/TenantPersistence.php';

function trAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function trSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message . '\nExpected: ' . var_export($expected,true) . '\nActual: ' . var_export($actual,true));
}

final class TenantRuntimeRepoPdo extends PDO
{
    public array $queue=[];
    public array $executed=[];
    public function __construct() {}
    public function push(array $response): void { $this->queue[]=$response; }
    public function prepare(string $query, array $options=[]): PDOStatement|false
    {
        return new TenantRuntimeRepoStatement($this,$query,array_shift($this->queue) ?? []);
    }
}
final class TenantRuntimeRepoStatement extends PDOStatement
{
    public function __construct(private TenantRuntimeRepoPdo $db,private string $sql,private array $response) {}
    public function execute(?array $params=null): bool
    {
        $this->db->executed[]=['sql'=>$this->sql,'params'=>$params ?? []];
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed
    { return $this->response['fetch'] ?? false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    { return $this->response['rows'] ?? []; }
    public function fetchColumn(int $column=0): mixed
    { return $this->response['column'] ?? false; }
    public function rowCount(): int { return (int)($this->response['row_count'] ?? 0); }
}

$db=new TenantRuntimeRepoPdo();
$p=SvAmazonTenantPersistence::create($db,new SvAmazonTenantContext(3,30));
$db->push(['column'=>'9']); trSame(9,$p->cases->countAll(),'Scoped case count.');
$db->push(['rows'=>[['amazon_order_id'=>'702-1'],['amazon_order_id'=>'702-2']]]); trSame(['702-1','702-2'],$p->cases->openOrderIds(25),'Scoped open order IDs.');
$db->push(['rows'=>[['id'=>1]]]); trSame([1],array_column($p->cases->financialCasesAfter(0,10),'id'),'Scoped keyset financial cases.');
$db->push(['rows'=>[['id'=>2]]]); trSame([2],array_column($p->cases->casesWithSafeTId(10),'id'),'Scoped SAFE-T cases.');
$db->push(['fetch'=>['total_cases'=>2,'at_risk'=>'10.00']]); trSame(2,(int)$p->cases->summary()['total_cases'],'Scoped summary.');
$db->push(['rows'=>[['id'=>2]]]); trSame([2],array_column($p->cases->recent(10),'id'),'Scoped recent cases.');
$db->push(['rows'=>[['id'=>7]]]); trSame([7],array_column($p->policies->allActive(),'id'),'Scoped active policies.');
$db->push(['column'=>'4']); trSame(4,$p->outbox->countPendingProcessing(),'Scoped pending outbox count.');
$db->push(['column'=>'1']); trSame(1,$p->outbox->countDeadLetters(),'Scoped dead letter count.');
$db->push(['column'=>'11']); trSame(true,$p->outbox->hasActive(5,'SAFE_T_READ'),'Scoped active job lookup.');
$db->push(['column'=>'6']); trSame(6,$p->cursors->count(),'Scoped cursor count.');
$db->push(['column'=>'8']); trSame(true,$p->events->existsByIdempotencyKey(str_repeat('a',64)),'Scoped event idempotency lookup.');
$db->push(['column'=>'9']); trSame(9,$p->events->findIdByIdempotencyKey(str_repeat('b',64)),'Scoped event ID lookup.');

foreach($db->executed as $execution){
    $sql=$execution['sql'];
    if(str_contains($sql,'amazon_return_policies')){
        trAssert(str_contains($sql,'tenant_id'),'Policy runtime SQL must be tenant scoped.');
        trSame(3,$execution['params'][':tenant_id'] ?? null,'Policy tenant bind.');
        continue;
    }
    trAssert(str_contains($sql,'tenant_id'),'Runtime repository SQL must include tenant_id.');
    trAssert(str_contains($sql,'amazon_connection_id'),'Connection-owned SQL must include connection scope.');
    trSame(3,$execution['params'][':tenant_id'] ?? null,'Tenant bind.');
    trSame(30,$execution['params'][':amazon_connection_id'] ?? null,'Connection bind.');
}

echo "amazon-returns-tenant-runtime-repositories-test: OK\n";
