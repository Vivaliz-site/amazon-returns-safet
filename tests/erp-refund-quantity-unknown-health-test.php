<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/CaseRepository.php';
final class QuantityHealthPdo extends PDO {
    public array $sql=[];
    public function __construct(){}
    public function prepare(string $sql,array $opts=[]):PDOStatement|false {
        $this->sql[]=$sql;
        return new QuantityHealthStatement();
    }
}
final class QuantityHealthStatement extends PDOStatement {
    public function execute(?array $params=null):bool {
        if($params!==[':tenant_id'=>1,':amazon_connection_id'=>2])throw new RuntimeException('Tenant and connection must be bound.');
        return true;
    }
    public function fetchColumn(int $column=0):mixed{return '15';}
}
$db=new QuantityHealthPdo();
$repo=new SvAmazonReturnCaseRepository($db,new SvAmazonTenantContext(1,2));
$count=$repo->countRefundQuantityUnconfirmed();
if($count!==15)throw new RuntimeException('Missing refund quantity count must not report a silent green.');
$sql=(string)end($db->sql);
foreach(['refund_at IS NOT NULL','refund_amount>0','quantity_refunded=0','tenant_id=:tenant_id','amazon_connection_id=:amazon_connection_id'] as $fragment) {
    if(!str_contains($sql,$fragment))throw new RuntimeException('Missing quantity audit SQL requirement: '.$fragment);
}
$runtime=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/Runtime.php');
foreach(['$erpRefundQuantityUnknown=$p->cases->countRefundQuantityUnconfirmed()','ERP_REFUND_QUANTITY_UNCONFIRMED',"'erp_refund_quantity_unconfirmed'=>\$erpRefundQuantityUnknown"] as $fragment){
    if(!str_contains($runtime,$fragment))throw new RuntimeException('Runtime health must disclose unconfirmed refund quantities.');
}
echo "erp-refund-quantity-unknown-health-test: OK\n";
