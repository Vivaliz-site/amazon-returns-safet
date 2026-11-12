<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/amazon-returns/CockpitReadFreshness.php';
function crfAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);

$event=['id'=>7,'event_type'=>'SAFE_T_STATUS_OBSERVED','source'=>'SELLER_CENTRAL','occurred_at'=>'2026-11-07 01:00:00'];
$job=['id'=>9,'kind'=>'SAFE_T_READ','status'=>'SUCCEEDED','updated_at'=>'2026-11-12 00:20:08'];

// RED reproduction: an unchanged status emits no new event, so only the job proves the fresh read.
$latest=SvAmazonCockpitReadFreshness::latest([$event],[$job]);
crfAssert(($latest['occurred_at']??null)==='2026-11-12 00:20:08','A newer successful read job must be the last verification.');
crfAssert(($latest['source']??null)==='OUTBOX_READ'&&($latest['event_type']??null)==='SAFE_T_READ','Job proof must be labelled as an outbox read.');

// A newer observation event still wins over an older job.
$newerEvent=$event;$newerEvent['occurred_at']='2026-11-12 03:00:00';
crfAssert((SvAmazonCockpitReadFreshness::latest([$newerEvent],[$job])['id']??0)===7,'A newer event must win over an older job.');

// Only successful read jobs count: failures, retries and writes never prove freshness.
foreach(['FAILED','PENDING','PROCESSING','SUPERSEDED','DEAD'] as $status){
    $bad=$job;$bad['status']=$status;
    crfAssert(SvAmazonCockpitReadFreshness::latest([$event],[$bad])['id']===7,"Status $status must not count as a verification.");
}
$write=$job;$write['kind']='SAFE_T_SUBMIT';
crfAssert(SvAmazonCockpitReadFreshness::latest([],[$write])===null,'A write job is not a read verification.');
$otherEvent=['id'=>1,'event_type'=>'GMAIL_NOTE','occurred_at'=>'2026-11-12 01:00:00'];
crfAssert(SvAmazonCockpitReadFreshness::latest([$otherEvent],[])===null,'Unrelated events must not count.');

// Malformed or empty timestamps are ignored, never trusted.
foreach(['', 'not-a-date','2026-13-45 99:99:99',null] as $badAt){
    $broken=$job;$broken['updated_at']=$badAt;$broken['created_at']=null;
    crfAssert(SvAmazonCockpitReadFreshness::latest([],[$broken])===null,'Bad timestamp must be ignored.');
}
crfAssert(SvAmazonCockpitReadFreshness::latest([],[])===null,'No evidence means no verification.');

// Discovery, support and delivery reads also prove the case was re-read.
foreach(['SAFE_T_DISCOVERY','SELLER_SUPPORT_READ','ORDER_DELIVERY_READ'] as $kind){
    $other=$job;$other['kind']=$kind;
    crfAssert((SvAmazonCockpitReadFreshness::latest([],[$other])['event_type']??null)===$kind,"$kind must count.");
}

// Both the detail and the list API must use the same helper.
foreach(['case.php','cases.php'] as $api){
    $source=(string)file_get_contents($root.'/admin/amazon-returns/api/'.$api);
    crfAssert(str_contains($source,'CockpitReadFreshness.php')&&str_contains($source,'SvAmazonCockpitReadFreshness::latest('),"$api must use the shared freshness helper.");
}
echo "cockpit-read-freshness-test OK\n";
