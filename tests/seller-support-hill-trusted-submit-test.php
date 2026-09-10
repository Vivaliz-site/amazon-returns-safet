<?php
declare(strict_types=1);
function htAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
htAssert(str_contains($worker,'async clickHillButtonTrusted(selector)'),'Hill email submission must expose a trusted CDP click helper.');
$methodStart=strpos($worker,'async clickHillButtonTrusted(selector)');
$methodEnd=$methodStart===false?false:strpos($worker,'async selectKatOption',$methodStart);
htAssert($methodStart!==false && $methodEnd!==false,'Trusted Hill click helper must remain independently auditable.');
$method=substr($worker,(int)$methodStart,(int)$methodEnd-(int)$methodStart);
foreach(['Input.dispatchMouseEvent',"type: 'mousePressed'","type: 'mouseReleased'",'spl-hill-form','getBoundingClientRect'] as $needle){
    htAssert(str_contains($method,$needle),'Trusted Hill click must use real CDP pointer input with nested-frame coordinates: '.$needle);
}
$submitStart=strpos($worker,'async function submitHillEmail');
$submitEnd=$submitStart===false?false:strpos($worker,'async function currentSupportCaseId',$submitStart);
htAssert($submitStart!==false && $submitEnd!==false,'Hill email submit function must remain auditable.');
$submit=substr($worker,(int)$submitStart,(int)$submitEnd-(int)$submitStart);
htAssert(str_contains($submit,"clickHillButtonTrusted('#send-email-button')"),'Email Send must use the trusted CDP click helper.');
htAssert(!str_contains($submit,'button.click()'),'Synthetic JavaScript clicks must not be used for the final Hill Send action.');
htAssert(str_contains($worker,'async function hillSupportUnavailable'),'Live Amazon support unavailability must be distinguished from a successful write.');
htAssert(str_contains($worker,'No support agents are available right now'),'Observed global support-unavailable state must be recognized exactly.');
$contactStart=strpos($worker,'async function contactSupportAndReadBack');
$contactEnd=$contactStart===false?false:strpos($worker,'async function fillGeneralSupportIssue',$contactStart);
$contact=substr($worker,(int)$contactStart,(int)$contactEnd-(int)$contactStart);
htAssert(str_contains($contact,"bridgeResult('BLOCKED_UNTIL'"),'Temporary Seller Support unavailability must be deferred instead of dead-lettered.');
echo "seller-support-hill-trusted-submit-test: OK\n";
