<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/SafeTStatusService.php';

function asdpSame(mixed $expected,mixed $actual,string $message):void{
    if($expected!==$actual)throw new RuntimeException($message.' expected='.var_export($expected,true).' actual='.var_export($actual,true));
}

$case=[
    'id'=>14,
    'state'=>'APPEAL_SUBMITTED',
    'repeated_denial_count'=>0,
    'last_denial_fingerprint'=>null,
    'appeal_deadline_at'=>'2026-09-14 05:01:00',
];
$read=[
    'claim_status'=>'DENIED',
    'appeal_submitted'=>true,
    'appeal_denied'=>false,
    'decision_text'=>'Entendemos sua posição, mas reafirmamos nossa decisão sobre essa reivindicação.',
    'decision_fingerprint'=>str_repeat('a',64),
    'appeal_deadline_at'=>'2026-09-14 05:01:00',
];
$patch=SvAmazonSafeTStatusService::projection(
    $case,
    $read,
    true,
    new DateTimeImmutable('2026-09-27T00:01:41Z')
);
asdpSame('APPEAL_DENIED_FINAL',$patch['state']??null,'A DENIED status after a submitted appeal must advance to final appeal denial even when the UI parser omits appeal_denied=true.');
asdpSame('2026-09-27 00:01:41',$patch['next_action_at']??null,'A newly observed final appeal denial must become immediately actionable.');


$historicalCase=[
    'id'=>14,
    'state'=>'SAFE_T_SUBMITTED',
    'safe_t_id'=>'29787-26575-0026466',
    'appeal_deadline_at'=>'2026-09-14 05:01:00',
];
$historicalDenied=[
    'id'=>100,
    'case_id'=>14,
    'event_type'=>'SAFE_T_STATUS_OBSERVED',
    'source'=>'SELLER_CENTRAL',
    'occurred_at'=>'2026-09-27 00:01:41',
    'payload'=>[
        'safe_t_id'=>'29787-26575-0026466',
        'claim_status'=>'DENIED',
        'appeal_submitted'=>true,
        'appeal_denied'=>false,
        'decision_fingerprint'=>str_repeat('b',64),
    ],
];
$reconciled=SvAmazonSafeTStatusService::reconcileAfterAppealSubmission(
    $historicalCase,
    [$historicalDenied],
    new DateTimeImmutable('2026-10-03T23:00:00Z')
);
asdpSame('APPEAL_DENIED_FINAL',$reconciled['state']??null,'A prior DENIED observation for an already-submitted appeal must survive the appeal write result.');
asdpSame('2026-10-03 23:00:00',$reconciled['next_action_at']??null,'Recovered final denial must become immediately actionable.');

echo "appeal-submitted-denied-projection-test: OK\n";
