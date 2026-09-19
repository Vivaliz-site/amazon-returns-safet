<?php
declare(strict_types=1);
function oudAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$outbox=(string)file_get_contents(dirname(__DIR__).'/includes/amazon-returns/TenantOutbox.php');
$runtime=(string)file_get_contents(dirname(__DIR__).'/includes/amazon-returns/Runtime.php');
oudAssert(str_contains($outbox,'function countUiDriftPending()'),'Outbox must expose pending UI drift count.');
oudAssert(str_contains($outbox,"status='PENDING'"),'UI drift count must only cover pending jobs.');
oudAssert(str_contains($outbox,"last_error LIKE 'UI\\_DRIFT:%'"),'UI drift count must match deferred UI_DRIFT failures only.');
oudAssert(str_contains($runtime,"OUTBOX_UI_DRIFT_PENDING"),'Runtime health must degrade while a deferred UI drift remains pending.');
oudAssert(str_contains($runtime,"'ui_drift_pending'=>"),'Runtime health must expose UI drift pending count.');
echo "outbox-ui-drift-health-test: OK\n";
