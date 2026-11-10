<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/CaseConsultation.php';
function ssrtAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('SUPPORT_REFERENCE_READ: '.$why);}
$case=['id'=>13216,'support_case_id'=>'22403672081'];
$events=[
 ['event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
  'occurred_at'=>'2026-10-15 12:11:30','payload'=>['case_id'=>'22403672081']],
 ['event_type'=>'SELLER_SUPPORT_EMAIL','source'=>'GMAIL',
  'occurred_at'=>'2026-10-31 23:17:44','payload'=>['support_case_id'=>'22632279381']],
 ['event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED','source'=>'SELLER_CENTRAL',
  'occurred_at'=>'2026-11-09 03:11:12','payload'=>['case_id'=>'22632279381']],
];
$refs=SvAmazonCaseConsultation::supportCaseReferences($case,$events);
ssrtAssert(count($refs)===2,'Both official referenced and canonical support threads must be tracked.');
ssrtAssert($refs[0]['id']==='22632279381' && $refs[0]['is_primary']===false,
 'The newest source-backed thread must be checked before stale canonical support.');
$source=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/StatusBridgeService.php');
ssrtAssert(str_contains($source,'SvAmazonCaseConsultation::supportCaseReferences'),
 'Read scheduler must discover support threads from official Gmail and Seller Central evidence.');
ssrtAssert(str_contains($source,"'SELLER_SUPPORT_READ'") && str_contains($source,"'support_case_id'=>\$refId"),
 'Read scheduler must pass the referenced thread identity in the scoped job, not overwrite primary.');
ssrtAssert(str_contains($source,"'read_only'=>true") && str_contains($source,'readKeyForTimeline'),
 'Referenced support polling must be read-only and idempotent.');
echo "support-referenced-thread-scheduling-test: OK\n";
