<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/CaseRepository.php';
require_once __DIR__.'/../includes/amazon-returns/TenantOutbox.php';
function closedClaimAssert(bool $value,string $message):void{
    if(!$value)throw new RuntimeException($message);
}
final class ClosedClaimReadPdo extends PDO {
    public array $queries=[];
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $this->queries[]=$query;
        return new ClosedClaimReadStatement();
    }
}
final class ClosedClaimReadStatement extends PDOStatement {
    public function execute(?array $params=null):bool{return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,...$args):array{return [];}
    public function rowCount():int{return 0;}
}
$db=new ClosedClaimReadPdo();
$context=new SvAmazonTenantContext(1,1);
$cases=new SvAmazonReturnCaseRepository($db,$context);
$cases->casesWithSafeTId(250);
$sql=(string)end($db->queries);
closedClaimAssert(str_contains($sql,"safe_t_id<>''"),'Only bound cases with a real SAFE-T claim ID may be monitored.');
closedClaimAssert(!str_contains($sql,'closed_at IS NULL'),'Known SAFE-T claims must continue to be read even after a local CLOSED_LOSS date.');
closedClaimAssert(str_contains($sql,'tenant_id=:tenant_id') && str_contains($sql,'amazon_connection_id=:amazon_connection_id'),'Claim monitoring must remain tenant scoped.');
$outbox=new SvAmazonTenantReturnsOutbox($db,$context);
$outbox->supersedeTerminalReadJobs('TERMINAL_CASE_NO_LONGER_REQUIRES_READ');
$cancelSql=(string)end($db->queries);
closedClaimAssert(!str_contains($cancelSql,"'SAFE_T_READ'"),'Closing a local case must never cancel SAFE-T claim monitoring.');
closedClaimAssert(str_contains($cancelSql,"'SAFE_T_DISCOVERY'"),'Unclaimed terminal cases should still suppress unnecessary discovery.');
echo "safe-t-closed-claim-read-coverage-test: OK\n";
