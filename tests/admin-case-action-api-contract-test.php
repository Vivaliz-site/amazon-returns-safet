<?php
declare(strict_types=1);
function aca(bool $v,string $m):void{if(!$v)throw new RuntimeException($m);}
$root=dirname(__DIR__);$api=(string)file_get_contents($root.'/admin/amazon-returns/api/case-admin-action.php');$detail=(string)file_get_contents($root.'/admin/amazon-returns/api/case.php');$index=(string)file_get_contents($root.'/admin/amazon-returns/index.php');$service=(string)file_get_contents($root.'/includes/amazon-returns/AdminCaseOverride.php');$repo=(string)file_get_contents($root.'/includes/amazon-returns/CaseRepository.php');
foreach(['AdminAuth.php','Csrf.php','SvAmazonReturnsAdminAuth::requireLogin(true)','SvAmazonReturnsCsrf::valid','TenantRegistry','TenantPersistence','SvAmazonAdminCaseOverride::apply'] as $needle)aca(str_contains($api,$needle),'Endpoint missing required contract: '.$needle);
foreach(['CLOSE_LOSS','REOPEN','MARK_RECOVERED','REQUEST_REVIEW','PREPARE_CONTESTATION','SET_STATE','UPDATE_FIELDS'] as $action)aca(str_contains($service,$action),'Service action unavailable: '.$action);
aca(str_contains($api,"REQUEST_METHOD")&&str_contains($api,"'POST'"),'Endpoint must accept POST only.');
aca(str_contains($detail,"'admin_actions'=>['CLOSE_LOSS','REOPEN','MARK_RECOVERED','REQUEST_REVIEW','PREPARE_CONTESTATION','SET_STATE','UPDATE_FIELDS']"),'Case detail must expose the same action set for every case state.');
aca(str_contains($detail,"'admin_states'=>SvAmazonReturnStates::all()"),'Case detail must expose all valid states.');aca(str_contains($detail,'admin_state_override'),'Case detail must disclose the active manual state override.');aca(str_contains($detail,'admin_recovery_claim'),'Case detail must disclose pending recovery assertions.');
aca(str_contains($index,"token('case-admin-action')")&&str_contains($index,'id="case-admin-action-csrf"'),'Admin UI must receive scoped CSRF token.');
aca(str_contains($service,"'external_operation'=>'NOT_REQUESTED'")&&str_contains($service,"'financial_credit_changed'=>false"),'Audit must separate internal override from external result and credit.');
aca(str_contains($service,'reconciled_credit_amount'),'Service must document protected reconciled credit.');aca(str_contains($repo, "field === 'reconciled_credit_amount'"),'Repository must reject reconciled credit edits.');
aca(!str_contains($api,'outbox->claimBatch')&&!str_contains($api,'BridgeService'),'Manual admin action must not dispatch external Amazon writes.');
echo "admin-case-action-api-contract-test: OK\n";
