<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/ErpSalesReturnRepository.php';
final class AgedReturnPdo extends PDO {
    public array $sql=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $this->sql[]=$query;
        return new AgedReturnStatement();
    }
}
final class AgedReturnStatement extends PDOStatement {
    public function execute(?array $params=null):bool{return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        return ['amazon_order_id'=>'702-1111111-2222222','erp_sales_return_id'=>'1234','status'=>'RETURN_CREATED_WAITING_INVOICE'];
    }
}
$db=new AgedReturnPdo();
$repo=new SvAmazonErpSalesReturnRepository($db,new SvAmazonTenantContext(1,1));
$result=$repo->reactivateIgnoredRefund('702-1111111-2222222');
if(($result['status']??null)!=='RETURN_CREATED_WAITING_INVOICE')throw new RuntimeException('Reactivation must preserve existing return state.');
$writes=array_values(array_filter($db->sql,fn(string $sql)=>str_contains($sql,'UPDATE amazon_return_erp_sales_returns')));
if(count($writes)!==1)throw new RuntimeException('Reactivation must perform exactly one scoped atomic update.');
$sql=$writes[0];
foreach(["CASE WHEN return_invoice_id","WHEN erp_sales_return_id","'RETURN_CREATED_WAITING_INVOICE'","'RETURN_INVOICE_EXISTS'","'IGNORED_REFUND_OUTSIDE_OPERATIONAL_WINDOW'","'IGNORED_REFUND_OLDER_THAN_90D'","AND status IN","tenant_id=:tenant_id","amazon_connection_id=:amazon_connection_id"] as $required){
    if(!str_contains($sql,$required))throw new RuntimeException('Reactivation lost idempotency/ownership condition: '.$required);
}
if(str_contains($sql,"SET status='PENDING'"))throw new RuntimeException('Reactivation must never demote an existing ERP sales return to pending creation.');
echo "erp-aged-workflow-reactivation-test: OK\n";
