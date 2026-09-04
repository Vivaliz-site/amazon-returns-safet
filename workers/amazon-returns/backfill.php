<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__ . '/../../includes/amazon-returns/Enums.php';

final class SvAmazonReturnsBackfill
{
    /** @return array{from:DateTimeImmutable,to:DateTimeImmutable,days:int} */
    public static function window(?int $days = 180, ?DateTimeImmutable $now = null): array
    {
        $days ??= 180;
        if ($days < 1 || $days > 180) throw new InvalidArgumentException('Amazon returns backfill must be between 1 and 180 days.');
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $to = $now->setTimezone(new DateTimeZone('UTC'));
        return ['from'=>$to->sub(new DateInterval('P'.$days.'D')),'to'=>$to,'days'=>$days];
    }

    /** @return array{records:list<mixed>,next_cursor:?int} */
    public static function batch(array $records, int $cursor = 0, int $limit = 500): array
    {
        $cursor=max(0,$cursor); $limit=max(1,min(1000,$limit));
        $slice=array_values(array_slice($records,$cursor,$limit));
        $next=$cursor+count($slice);
        return ['records'=>$slice,'next_cursor'=>$next<count($records)?$next:null];
    }

    /** @return array{ingested:int,deduped:int,unclassified:int,d45_candidates:int,d60_candidates:int,already_reimbursed:int,active_safe_t:int,denied:int,support_escalation_candidates:int} */
    public static function summarize(array $snapshots, DateTimeImmutable $now): array
    {
        $unique=[]; $deduped=0;
        foreach ($snapshots as $index=>$case) {
            if (!is_array($case)) continue;
            $key=trim((string)($case['case_key'] ?? (($case['amazon_order_id'] ?? 'unknown').'|'.($case['amazon_order_item_id'] ?? $index))));
            if (isset($unique[$key])) { $deduped++; continue; }
            $unique[$key]=$case;
        }
        $out=['ingested'=>count($unique),'deduped'=>$deduped,'unclassified'=>0,'d45_candidates'=>0,'d60_candidates'=>0,'already_reimbursed'=>0,'active_safe_t'=>0,'denied'=>0,'support_escalation_candidates'=>0];
        foreach ($unique as $case) {
            $expected=(float)($case['expected_reimbursement_amount'] ?? $case['refund_amount'] ?? 0);
            $credited=(float)($case['reconciled_credit_amount'] ?? 0);
            if ($expected>0 && $credited+0.00001 >= $expected) { $out['already_reimbursed']++; continue; }
            $state=(string)($case['state'] ?? ''); $safeT=trim((string)($case['safe_t_id'] ?? ''));
            if (in_array($state,[SvAmazonReturnStates::SAFE_T_DENIED,SvAmazonReturnStates::APPEAL_REQUIRED,SvAmazonReturnStates::APPEAL_DENIED_FINAL],true)) {
                $out['denied']++;
                if ((int)($case['repeated_denial_count'] ?? 0)>0 && trim((string)($case['support_case_id'] ?? ''))==='') $out['support_escalation_candidates']++;
                continue;
            }
            if ($safeT!=='') { $out['active_safe_t']++; continue; }
            $initiator=(string)($case['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN);
            $program=(string)($case['program'] ?? SvAmazonReturnPrograms::UNKNOWN);
            if ($initiator===SvAmazonRefundInitiators::UNKNOWN || $program===SvAmazonReturnPrograms::UNKNOWN) { $out['unclassified']++; continue; }
            $policy=SvAmazonReturnPolicyEngine::evaluate($case,$now);
            if (($policy['state'] ?? null)===SvAmazonReturnStates::POLICY_REVIEW_REQUIRED) { $out['unclassified']++; continue; }
            if (($policy['eligible'] ?? false)!==true) continue;
            if (in_array($program,[SvAmazonReturnPrograms::FBA_ONSITE,SvAmazonReturnPrograms::DELIVERY_BY_AMAZON],true) && self::isPostExceptionEffective($case)) $out['d60_candidates']++;
            else $out['d45_candidates']++;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function casesFromGmail(array $messages): array
    {
        require_once __DIR__ . '/../../includes/amazon-returns/GmailParser.php';
        $parser=new SvAmazonGmailParser(); $cases=[];
        foreach ($messages as $message) {
            if (!is_array($message)) continue;
            foreach ($parser->parse($message) as $event) {
                $order=(string)$event['order_id'];
                $case=$cases[$order] ?? ['case_key'=>$order.'|gmail','amazon_order_id'=>$order,'amazon_order_item_id'=>'gmail-unresolved','marketplace_id'=>'BR','program'=>'UNKNOWN','refund_initiator'=>'UNKNOWN','quantity_ordered'=>1,'quantity_refunded'=>0,'quantity_received'=>0,'physical_status'=>'NOT_RECEIVED','state'=>'AWAITING_RETURN','expected_reimbursement_amount'=>'0.00','reconciled_credit_amount'=>'0.00','safe_t_id'=>null,'gmail_event_types'=>[]];
                $case['gmail_event_types'][]=$event['event_type'];
                if ($event['event_type']==='REFUND_ISSUED_EMAIL') { $case['refund_at']=$event['occurred_at']; $case['refund_amount']=$event['amount']; $case['expected_reimbursement_amount']=$event['amount']; $case['quantity_refunded']=1; }
                if (str_starts_with($event['event_type'],'SAFE_T_')) { $case['safe_t_id']=$event['safe_t_id']; $case['state']=$event['event_type']==='SAFE_T_REGISTERED_EMAIL'?'SAFE_T_SUBMITTED':($case['state'] ?? 'SAFE_T_SUBMITTED'); }
                $cases[$order]=$case;
            }
        }
        return array_values($cases);
    }

    private static function isPostExceptionEffective(array $case): bool
    {
        $raw=(string)($case['order_at'] ?? '');
        return $raw!=='' && substr($raw,0,10)>='2026-04-21';
    }
}
