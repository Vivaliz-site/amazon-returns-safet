<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';
function exactCheck(bool $condition,string $why):void {
    if(!$condition)throw new RuntimeException($why);
}
$hash=hash('sha256','synthetic write content');
$old=hash('sha256','prior episode');
$row=['id'=>812,'case_id'=>36,'kind'=>'SELLER_SUPPORT_UPDATE','attempt_count'=>5,
    'payload'=>['write_snapshot'=>['content_sha256'=>$hash]]];
$event=['event_type'=>'SELLER_CENTRAL_ACTION_RESULT','source'=>'SELLER_CENTRAL',
    'source_event_id'=>'812',
    'payload'=>['action'=>'SELLER_SUPPORT_UPDATE','status'=>'UI_DRIFT',
        'reason'=>'SUPPORT_WRITE_WITHOUT_READBACK_ID','submitted'=>true,
        'write_content_sha256'=>$hash]];
exactCheck(SvAmazonReturnsRemoteBridge::supportRetryReconciliationEligible($row,[])===false,
    'A high historical attempt_count alone cannot authorize an assumed retry.');
$pre=$event;$pre['payload']['submitted']=false;
exactCheck(SvAmazonReturnsRemoteBridge::supportRetryReconciliationEligible($row,[$pre])===false,
    'Pre-write drift cannot authorize post-write reconciliation.');
exactCheck(SvAmazonReturnsRemoteBridge::supportRetryReconciliationEligible($row,[$event])===true,
    'Ambiguous submitted write with exact episode and hash must require reconciliation.');
$other=$event;$other['payload']['write_content_sha256']=$old;
exactCheck(SvAmazonReturnsRemoteBridge::supportRetryReconciliationEligible($row,[$other])===false,
    'An earlier write-content hash must not authorize retry of a newly rearmed episode.');
$wrongOutbox=$event;$wrongOutbox['source_event_id']='900';
exactCheck(SvAmazonReturnsRemoteBridge::supportRetryReconciliationEligible($row,[$wrongOutbox])===false,
    'Different outbox source event must be excluded.');
$wrongSource=$event;$wrongSource['source']='ADMIN_INTAKE';
exactCheck(SvAmazonReturnsRemoteBridge::supportRetryReconciliationEligible($row,[$wrongSource])===false,
    'Untrusted event source must be excluded.');
$accepted=$event;
$accepted['payload']['status']='ACCEPTED';
$accepted['payload']['reason']='SUPPORT_CASE_UPDATED_AND_READ_BACK';
exactCheck(SvAmazonReturnsRemoteBridge::supportRetryReconciliationEligible($row,[$event,$accepted])===false,
    'Read-back confirmed result must end ambiguity.');
$worker=file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
exactCheck(is_string($worker) && str_contains($worker,'const retryReconciliation = job.retry_reconciliation === true;'),
    'Worker must use server-owned retry decision, never attempt_count.');
exactCheck(str_contains($worker,"reason: 'SUPPORT_AMBIGUOUS_PRIOR_SUBMISSION_UNRESOLVED'"),
    'Unresolved prior submit must fail closed before any new write.');
$start=strpos($worker,'async function supportOpen');
$end=strpos($worker,'async function supportUpdate',$start);
exactCheck($start!==false && $end!==false,'Support Open section must exist.');
$body=substr($worker,$start,$end-$start);
exactCheck(strpos($body,"if (retryReconciliation) return bridgeResult('HUMAN_INTERVENTION_REQUIRED'")<strpos($body,'await cdp.navigate(HELP_URL, 6000)'),
    'Manual intervention gate must precede any support write navigation.');
exactCheck(!str_contains($body,'Number(job.attempt_count'),
    'Raw attempt counter must not authorize a support retry.');
echo "seller-support-exact-episode-retry-test: OK\n";
