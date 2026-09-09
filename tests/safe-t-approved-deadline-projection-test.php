<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/SafeTStatusService.php';

$case=[
    'id'=>13246,
    'state'=>'CREDIT_PENDING',
    'appeal_deadline_at'=>null,
    'repeated_denial_count'=>0,
    'last_denial_fingerprint'=>null,
];
$read=[
    'claim_status'=>'APPROVED',
    'safe_t_id'=>'91582-36431-8749346',
    'appeal_deadline_at'=>'2026-08-24 05:50:00',
    'appeal_denied'=>false,
    'decision_fingerprint'=>null,
];
$patch=SvAmazonSafeTStatusService::projection(
    $case,$read,true,new DateTimeImmutable('2026-09-09 18:40:00',new DateTimeZone('UTC'))
);
if(($patch['appeal_deadline_at']??null)!=='2026-08-24 05:50:00'){
    throw new RuntimeException('Approved SAFE-T must preserve the official appeal deadline from the current Seller Central read: '.json_encode($patch));
}

$stale=$case;
$stale['appeal_deadline_at']='2026-08-01 00:00:00';
$withoutCurrentDeadline=$read;
$withoutCurrentDeadline['appeal_deadline_at']=null;
$patch=SvAmazonSafeTStatusService::projection(
    $stale,$withoutCurrentDeadline,true,new DateTimeImmutable('2026-09-09 18:40:00',new DateTimeZone('UTC'))
);
if(!array_key_exists('appeal_deadline_at',$patch) || $patch['appeal_deadline_at']!==null){
    throw new RuntimeException('Approved SAFE-T without a current deadline must clear a stale deadline from an older decision: '.json_encode($patch));
}

echo "safe-t-approved-deadline-projection-test: OK\n";
