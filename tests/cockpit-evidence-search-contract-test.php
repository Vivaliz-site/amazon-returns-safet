<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$class=(string)file_get_contents($root.'/includes/amazon-returns/InvoiceSearch.php');
$api=(string)file_get_contents($root.'/admin/amazon-returns/api/cases.php');
function ces(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['tenant_id=:cockpit_tenant_id','amazon_connection_id=:cockpit_connection_id','invoice_number','amazon_rma_id','merchant_rma_id','tracking_id','safe_t_claim_id'] as $needle){
    ces(str_contains($class,$needle),'Evidence search missing '.$needle);
}
ces(str_contains($api,'SvAmazonInvoiceSearch::caseIdsForCockpit'),'Cases API must use scoped evidence search.');
ces(str_contains($api,"'support_case_id'"),'Cases search must include Seller Support case id.');
$page=(string)file_get_contents($root.'/admin/amazon-returns/index.php');
$operational=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');
foreach(['Atrasados','Aguardando Amazon','Crédito pendente','chamado, RMA'] as $needle)ces(str_contains($page,$needle),'Quick search UX missing '.$needle);
foreach(["'overdue'","'amazon'","'credit'","q.set('bucket'"] as $needle)ces(str_contains($operational,$needle),'Operational filter behavior missing '.$needle);
echo "cockpit-evidence-search-contract-test: OK\n";