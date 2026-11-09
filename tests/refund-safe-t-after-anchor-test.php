<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RefundDiscovery.php';
require_once __DIR__.'/../includes/amazon-returns/StatusBridgeService.php';

function anchorAssert(bool $ok, string $why): void
{
    if (!$ok) throw new RuntimeException($why);
}
$older='123-1111111-1111111';
$recent='123-2222222-2222222';
$unverified='123-3333333-3333333';
$transport=new class($older,$recent,$unverified) {
    public function __construct(private string $older,private string $recent,private string $unverified) {}
    public function marketplaceId(): string { return 'BR_TEST'; }
    public function request(string $method,string $path,array $query): array
    {
        anchorAssert($method==='GET','Reconciling refunds may only GET SP-API Finances');
        anchorAssert($path==='/finances/2024-06-19/transactions','Wrong Finances endpoint');
        $row=static fn(string $order, ?string $posted): array=>[
            'transactionType'=>'Refund','transactionStatus'=>'RELEASED',
            'relatedIdentifiers'=>[['relatedIdentifierName'=>'ORDER_ID','relatedIdentifierValue'=>$order]],
        ]+($posted!==null?['postedDate'=>$posted]:[]);
        return ['status'=>200,'data'=>['transactions'=>[
            $row($this->older,'2026-11-03T09:00:00Z'),
            $row($this->recent,'2026-11-07T09:00:00Z'),
            $row($this->unverified,null),
            $row($this->recent,'2026-11-06T09:00:00Z'),
        ]]];
    }
};
$now=new DateTimeImmutable('2026-11-08T18:00:00Z');
$latest=new DateTimeImmutable('2026-11-05T12:00:00Z');
anchorAssert(method_exists(SvAmazonReturnCaseRepository::class,'latestConfirmedSafeTObservation'),'Missing last confirmed SAFE-T database read');
anchorAssert(method_exists(SvAmazonReturnsStatusBridgeService::class,'ensureRefundClaimChecks'),'Missing Seller Central reconciliation scheduling');
$scan=SvAmazonRefundDiscovery::scan($transport,10,$now,static fn():null=>null,$latest);
anchorAssert($scan['orders']===[$older,$recent,$unverified],'90-day refund completeness must preserve all orders');
anchorAssert($scan['orders_after_latest_safe_t']===[$recent],'Only refund posted after the SAFE-T anchor is new');
anchorAssert($scan['orders_with_unknown_refund_date']===[$unverified],'Unknown refund dates need external review rather than omission');
$withoutAnchor=SvAmazonRefundDiscovery::scan($transport,10,$now,static fn():null=>null);
anchorAssert($withoutAnchor['orders_after_latest_safe_t']===[$older,$recent,$unverified],'No confirmed claim must not skip released refunds');

final class AnchorPdo extends PDO {
    public array $queries=[];
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return new AnchorStatement($this,$query);
    }
}
final class AnchorStatement extends PDOStatement {
    public function __construct(private AnchorPdo $db,private string $sql) {}
    public function execute(?array $params=null): bool {
        $this->db->queries[]=['sql'=>$this->sql,'params'=>$params];
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {
        return ['case_id'=>9,'safe_t_id'=>'12345-12345-1234567','confirmed_at'=>'2026-11-05 12:00:00'];
    }
}
$db=new AnchorPdo();
$repository=new SvAmazonReturnCaseRepository($db,new SvAmazonTenantContext(1,1));
$claim=$repository->latestConfirmedSafeTObservation();
anchorAssert($claim['safe_t_id']==='12345-12345-1234567','Anchor must preserve external claim ID');
anchorAssert($claim['confirmed_at']==='2026-11-05 12:00:00','Anchor must use verified event date');
$sql=$db->queries[0]['sql']??'';
$params=$db->queries[0]['params']??[];
anchorAssert(str_contains($sql,'amazon_return_events')&&str_contains($sql,'amazon_return_cases'),'Claim anchor must validate event and case');
anchorAssert(str_contains($sql,'SAFE_T_ID_DISCOVERED')&&str_contains($sql,'SELLER_CENTRAL_ACTION_RESULT'),'Only confirmed external claim observations may anchor');
anchorAssert(($params[':tenant_id']??null)===1 && ($params[':amazon_connection_id']??null)===1,'Anchor must be tenant and connection scoped');
// Exercise the actual scoped outbox enqueue path without network, provider
// credentials, another worker, or any SAFE_T_SUBMIT action.
final class RefundQueuePdo extends PDO {
    public array $executed=[];
    public array $byOrder=[];
    private int $lastId=1000;
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return new RefundQueueStatement($this,$query);
    }
    public function lastInsertId(?string $name=null): string|false {
        return (string)++$this->lastId;
    }
}
final class RefundQueueStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private RefundQueuePdo $db,private string $sql) {}
    public function execute(?array $params=null): bool {
        $this->params=$params??[];
        $this->db->executed[]=['sql'=>$this->sql,'params'=>$this->params];
        return true;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        if(str_contains($this->sql,'FROM amazon_return_cases WHERE amazon_order_id=')){
            return $this->db->byOrder[$this->params[':order_id']??'']??[];
        }
        return [];
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed {
        if(str_contains($this->sql,'SELECT id FROM amazon_return_cases WHERE id=')){
            return ['id'=>$this->params[':case_id']??0];
        }
        return false;
    }
    public function fetchColumn(int $column=0): mixed { return false; }
}
$queueDb=new RefundQueuePdo();
$queueDb->byOrder=[
    $older=>[['id'=>10,'safe_t_id'=>'12345-12345-1234567']],
    $recent=>[['id'=>11,'safe_t_id'=>null,'closed_at'=>null,'amazon_order_item_id'=>'SINGLE-ITEM']],
    $unverified=>[
        ['id'=>12,'safe_t_id'=>null,'amazon_order_item_id'=>'ITEM-1'],
        ['id'=>13,'safe_t_id'=>null,'amazon_order_item_id'=>'ITEM-2'],
    ],
];
$scoped=SvAmazonTenantPersistence::create($queueDb,new SvAmazonTenantContext(1,1));
$enqueue=(new SvAmazonReturnsStatusBridgeService($scoped))
    ->ensureRefundClaimChecks([$older,$recent,$recent,$unverified],$now);
anchorAssert($enqueue['enqueued']===1 && $enqueue['locally_linked']===1
    && $enqueue['ambiguous']===1,'Only unlinked single-item refunds should get new Seller Central reads');
$inserts=array_values(array_filter($queueDb->executed,
    static fn(array $item):bool=>str_starts_with($item['sql'],'INSERT INTO amazon_return_outbox')));
anchorAssert(count($inserts)===1,'A duplicated order must not enqueue duplicate read jobs');
$bind=$inserts[0]['params'];
$payload=json_decode((string)$bind[':payload_json'],true,512,JSON_THROW_ON_ERROR);
anchorAssert($bind[':kind']==='SAFE_T_DISCOVERY' && ($payload['read_only']??false)===true,
    'Discovery must remain strictly read-only through the existing worker');
anchorAssert(($bind[':tenant_id']??null)===1 && ($bind[':amazon_connection_id']??null)===1,
    'Read job must be scoped to the current tenant and connection');
anchorAssert(!str_contains(json_encode($inserts,JSON_THROW_ON_ERROR),'SAFE_T_SUBMIT'),
    'A refund must not be converted into an automatic SAFE-T submission');

echo "REFUND_SAFE_T_AFTER_ANCHOR=PASS\n";
