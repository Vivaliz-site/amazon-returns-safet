<?php
declare(strict_types=1);

$root=dirname(__DIR__);
require_once $root.'/includes/amazon-returns/CockpitHealth.php';
function cacAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function cacSame(mixed $want,mixed $got,string $why):void{if($want!==$got)throw new RuntimeException($why.' expected='.json_encode($want).' actual='.json_encode($got));}

cacSame('DEGRADED',SvAmazonCockpitHealth::operatorStatus(0,1),'Operational issue without human review cannot be NORMAL.');
cacSame('USER_ACTION_REQUIRED',SvAmazonCockpitHealth::operatorStatus(1,1),'Human review must take precedence over operational issue.');
cacSame('NORMAL',SvAmazonCockpitHealth::operatorStatus(0,0),'No human work and no fault is healthy.');

$health=(string)file_get_contents($root.'/includes/amazon-returns/CockpitHealth.php');
$summary=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-summary.js');
$ui=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational.js');
$bootstrap=(string)file_get_contents($root.'/admin/amazon-returns/assets/cockpit-operational-bootstrap.js');
$cases=(string)file_get_contents($root.'/admin/amazon-returns/api/cases.php');
$intake=(string)file_get_contents($root.'/admin/amazon-returns/api/intake-lookup.php');
$search=(string)file_get_contents($root.'/includes/amazon-returns/CaseReferenceSearch.php');

cacAssert(!str_contains($health,"return ['status'=>'OK','observed_at'=>null,'reason'=>'configured'];"),'Configured connector without an observed run cannot be reported healthy.');
cacAssert(str_contains($health,'if($observedAt===null)'),'Connector metadata without a valid observation time must not be reported healthy.');
cacAssert(str_contains($summary,'Últimos dados disponíveis'),'Cached summary must visibly identify stale fallback data.');
cacAssert(str_contains($summary,'Resumo operacional indisponível no momento. Não foi possível confirmar se existem pendências.'),'No-cache summary failure must be unavailable, never healthy zero.');
cacAssert(str_contains($summary,"'Nenhuma ação sua é necessária.'"),'Zero human work must have a compact reassuring state.');
cacAssert(str_contains($ui,'function operatorFinancialSummary(c)'),'Zero-balance semantics must be centralized.');
cacAssert(str_contains($ui,"label:'Nenhum saldo financeiro em aberto.'"),'Zero balance without credit evidence must stay neutral.');
cacAssert(str_contains($ui,"label:'Crédito identificado; aguardando confirmação final.'"),'Pending reconciled credit needs precise copy.');
cacAssert(str_contains($ui,'return_tracking_ids'),'TBR/return tracking must be distinct from customer delivery tracking.');
cacAssert(str_contains($search,'SvAmazonInvoiceSearch::caseIdsExact'),'NF must use shared reference resolution.');
cacAssert(str_contains($cases,'SvAmazonCaseReferenceSearch::caseIds'),'Cockpit must use shared reference resolution.');
cacAssert(str_contains($intake,'SvAmazonCaseReferenceSearch::caseIds'),'Intake must use the same shared reference resolution.');
cacAssert(str_contains($cases,'SvAmazonGmailReturnReferenceLookup'),'Historical TBR fallback must remain read-only Gmail lookup.');
cacAssert(str_contains($cases,'$requiresPostFilter=$filters->requiresDecisionFilter();'),'Text search must not activate the 1000-row decision post-filter.');
cacAssert(str_contains($ui,"details.className='case-history-toggle'"),'Timeline must remain collapsed by default.');
cacAssert(str_contains($ui,"details.className='decision-explanation'"),'Decision explanation must remain collapsed by default.');
echo "cockpit-autonomy-consistency-test: OK\n";
