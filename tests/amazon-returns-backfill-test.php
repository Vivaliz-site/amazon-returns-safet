<?php

declare(strict_types=1);

require_once __DIR__ . '/../workers/amazon-returns/backfill.php';
require_once __DIR__ . '/../workers/amazon-returns/policy-monitor.php';

function bfSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) throw new RuntimeException($message.'\nExpected: '.var_export($expected,true).'\nActual: '.var_export($actual,true)); }
function bfAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$now=new DateTimeImmutable('2026-09-01T12:00:00Z');
$w180=SvAmazonReturnsBackfill::window(180,$now); bfSame('2026-03-05',$w180['from']->format('Y-m-d'),'180-day backfill start.'); bfSame('2026-09-01',$w180['to']->format('Y-m-d'),'Backfill end.');
$w90=SvAmazonReturnsBackfill::window(90,$now); bfSame('2026-06-03',$w90['from']->format('Y-m-d'),'90-day backfill start.');
$thrown=false; try{SvAmazonReturnsBackfill::window(181,$now);}catch(InvalidArgumentException){$thrown=true;} bfAssert($thrown,'Backfill must be bounded to 180 days.');

$policies=[
 ['id'=>1,'status'=>'ACTIVE','marketplace_id'=>'BR','program'=>'STANDARD','effective_from'=>'2020-01-01','effective_to'=>null,'eligibility_days'=>45,'basis'=>'SELLER_DEBIT_AT'],
 ['id'=>2,'status'=>'ACTIVE','marketplace_id'=>'BR','program'=>'FBA_ONSITE','effective_from'=>'2026-04-21','effective_to'=>null,'eligibility_days'=>60,'basis'=>'SELLER_DEBIT_AT'],
 ['id'=>3,'status'=>'ACTIVE','marketplace_id'=>'BR','program'=>'DELIVERY_BY_AMAZON','effective_from'=>'2026-04-21','effective_to'=>null,'eligibility_days'=>60,'basis'=>'SELLER_DEBIT_AT'],
];
$base=['marketplace_id'=>'BR','program'=>'STANDARD','order_at'=>'2026-05-01 10:00:00','refund_initiator'=>'AMAZON_AUTOMATIC','refund_at'=>'2026-06-01 10:00:00','seller_debit_at'=>'2026-06-01 10:00:00','quantity_ordered'=>1,'quantity_refunded'=>1,'quantity_received'=>0,'physical_status'=>'NOT_RECEIVED','state'=>'AWAITING_RETURN','expected_reimbursement_amount'=>'100.00','reconciled_credit_amount'=>'0.00','policies'=>$policies];
$cases=[
 ['case_key'=>'o1|i1']+$base,
 ['case_key'=>'o1|i1']+$base,
 ['case_key'=>'o2|i1']+array_replace($base,['program'=>'DELIVERY_BY_AMAZON','seller_debit_at'=>'2026-06-20 10:00:00','refund_at'=>'2026-06-20 10:00:00']),
 ['case_key'=>'o3|i1']+array_replace($base,['refund_initiator'=>'UNKNOWN']),
 ['case_key'=>'o4|i1']+array_replace($base,['reconciled_credit_amount'=>'100.00','state'=>'RECOVERED']),
 ['case_key'=>'o5|i1']+array_replace($base,['safe_t_id'=>'12345-67890-1234567','state'=>'SAFE_T_SUBMITTED']),
 ['case_key'=>'o6|i1']+array_replace($base,['safe_t_id'=>'22222-33333-4444444','state'=>'SAFE_T_DENIED']),
 ['case_key'=>'o7|i1']+array_replace($base,['safe_t_id'=>'77777-88888-9999999','state'=>'SAFE_T_DENIED','repeated_denial_count'=>2,'support_case_id'=>null]),
];
$summary=SvAmazonReturnsBackfill::summarize($cases,$now);
bfSame(7,$summary['ingested'],'Unique cases ingested.'); bfSame(1,$summary['deduped'],'Duplicate case snapshot deduped.');
bfSame(1,$summary['unclassified'],'Unknown initiator classified for review.'); bfSame(1,$summary['d45_candidates'],'One normal D+45 candidate.'); bfSame(1,$summary['d60_candidates'],'One DBA D+60 candidate.');
bfSame(1,$summary['already_reimbursed'],'Already credited case.'); bfSame(1,$summary['active_safe_t'],'Active SAFE-T count.'); bfSame(2,$summary['denied'],'Denied SAFE-T count.'); bfSame(1,$summary['support_escalation_candidates'],'Repeated denial candidate for Help.');

$batch=SvAmazonReturnsBackfill::batch(array_values($cases),0,3); bfSame(3,count($batch['records']),'Batch limit honored.'); bfSame(3,$batch['next_cursor'],'Backfill cursor advances.');
$batch2=SvAmazonReturnsBackfill::batch(array_values($cases),$batch['next_cursor'],20); bfSame(null,$batch2['next_cursor'],'Cursor completes after final batch.');

$existing=[['id'=>10,'policy_key'=>'RETURN_NOT_RECEIVED','marketplace_id'=>'BR','program'=>'STANDARD','effective_from'=>'2020-01-01','eligibility_days'=>45,'source_hash'=>'aaa','status'=>'ACTIVE']];
$original=$existing;
$monitor=new SvAmazonReturnPolicyMonitor();
$unchanged=$monitor->candidate($existing,['policy_key'=>'RETURN_NOT_RECEIVED','marketplace_id'=>'BR','program'=>'STANDARD','effective_from'=>'2020-01-01','eligibility_days'=>45,'source_url'=>'https://sellercentral.amazon.com.br/help/example','source_hash'=>'aaa']);
bfSame('UNCHANGED',$unchanged['status'],'Same policy hash is unchanged.'); bfSame($original,$existing,'Policy monitor must never rewrite historical input.');
$new=$monitor->candidate($existing,['policy_key'=>'RETURN_NOT_RECEIVED','marketplace_id'=>'BR','program'=>'STANDARD','effective_from'=>'2026-09-15','eligibility_days'=>50,'source_url'=>'https://sellercentral.amazon.com.br/help/new','source_hash'=>'bbb']);
bfSame('NEW_VERSION_CANDIDATE',$new['status'],'New effective policy becomes candidate version.'); bfSame('2026-09-15',$new['candidate']['effective_from'],'Candidate keeps explicit effective date.');
$conflict=$monitor->candidate($existing,['policy_key'=>'RETURN_NOT_RECEIVED','marketplace_id'=>'BR','program'=>'STANDARD','effective_from'=>'2020-01-01','eligibility_days'=>50,'source_url'=>'https://sellercentral.amazon.com.br/help/changed','source_hash'=>'ccc']);
bfSame('SOURCE_CHANGED_REVIEW_REQUIRED',$conflict['status'],'Changed source with same effective date requires review instead of rewriting history.');

echo "amazon-returns-backfill-test: OK\n";
