<?php
declare(strict_types=1);
function oudAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$outbox=(string)file_get_contents(dirname(__DIR__).'/includes/amazon-returns/TenantOutbox.php');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/amazon-returns/Runtime.php');
oudAssert(str_contains($outbox,'function countUiDriftPending()'),'Outbox must expose pending UI drift count.');
oudAssert(str_contains($outbox,'function pendingUiDriftReasonCodes()'),'Outbox must expose normalized pending UI drift reason codes.');
oudAssert(str_contains($outbox,"status='PENDING'"),'UI drift count must only cover pending jobs.');
oudAssert(str_contains($outbox,"LEFT(last_error,9)='UI_DRIFT:'"),'UI drift count must use a MySQL-safe exact UI_DRIFT prefix match.');
oudAssert(str_contains($runtime,"OUTBOX_UI_DRIFT_PENDING"),'Runtime health must degrade while a deferred UI drift remains pending.');
oudAssert(str_contains($runtime,"'ui_drift_pending'=>"),'Runtime health must expose UI drift pending count.');
oudAssert(str_contains($runtime,"'ui_drift_reason_codes'=>"),'Runtime health must expose normalized UI drift reason codes internally.');
oudAssert(str_contains($runtime,"OUTBOX_UI_DRIFT_REASON_"),'Public blockers must carry an allowlisted normalized UI drift stage without payload data.');
oudAssert(str_contains($outbox,"'SAFE_T_ELIGIBILITY_BUTTON_MISSING'"),'SAFE-T UI drift reasons must be explicitly allowlisted.');
oudAssert(str_contains($outbox,"'SAFE_T_ITEM_QUANTITY_NOT_WRITABLE'"),'SAFE-T item quantity drift must be allowlisted without exposing payload data.');
oudAssert(str_contains($outbox,"'SAFE_T_ITEM_NEXT_UNAVAILABLE'"),'SAFE-T item next-step drift must be allowlisted without exposing payload data.');
oudAssert(str_contains($outbox,"'OTHER'"),'Unknown UI drift values must collapse to a non-sensitive OTHER category.');
echo "outbox-ui-drift-health-test: OK\n";
