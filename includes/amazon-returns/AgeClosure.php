<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/RecoveryWindow.php';

/**
 * Owner-authorized, internal-only administrative closure after 90 UTC days.
 *
 * This never claims an Amazon reimbursement, cancels an Amazon support case, or
 * turns a loss into RECOVERED. It preserves partial reconciled credits and
 * writes exactly one durable audit event in the same transaction as closure.
 */
final class SvAmazonAgeClosure
{
    public const TERMINAL_REASON = 'OWNER_ADMINISTRATIVE_CLOSURE_AFTER_90D';
    public const EVENT_TYPE = 'CASE_CLOSED_ADMIN_90D';

    public function __construct(private SvAmazonTenantPersistence $persistence) {}

    /**
     * @return array{status:string,scanned:int,closed:int,deferred_active_outbox:int,unrecovered_brl:string,cutoff_at:string}
     */
    public function run(DateTimeImmutable $now, int $limit = 250): array
    {
        $db = $this->persistence->db();
        $scope = $this->persistence->context();
        $utc = $now->setTimezone(new DateTimeZone('UTC'));
        $cutoff = $utc->sub(new DateInterval('P' . SvAmazonRecoveryWindow::DAYS . 'D'))
            ->format('Y-m-d H:i:s');
        $occurredAt = $utc->format('Y-m-d H:i:s');
        $limit = max(1, min(500, $limit));
        $ids = $this->persistence->cases->agedUnreceivedCaseIds($cutoff,$limit);

        $closed = 0;
        $deferred = 0;
        $cents = 0;
        foreach ($ids as $id) {
            $db->beginTransaction();
            try {
                $case = $this->persistence->cases->lockAgedUnreceivedCase($id,$cutoff);
                if (!is_array($case)) {
                    $db->commit();
                    continue;
                }
                if ($this->persistence->cases->hasPendingAgeCloseOutbox($id)) {
                    $deferred++;
                    $db->commit();
                    continue;
                }

                $expectedCents = (int) round((float)$case['expected_reimbursement_amount'] * 100);
                $creditCents = (int) round((float)$case['reconciled_credit_amount'] * 100);
                $gapCents = max(0, $expectedCents - $creditCents);
                $this->persistence->events->append([
                    'case_id'=>$id,
                    'event_type'=>self::EVENT_TYPE,
                    'source'=>'INTERNAL',
                    'source_event_id'=>'OWNER_AUTO_90D_CASE_' . $id,
                    'idempotency_key'=>hash('sha256',implode('|',[
                        'owner-auto-90d', $scope->scopeKey(), (string)$id,
                    ])),
                    'occurred_at'=>$occurredAt,
                    'payload'=>[
                        'closure_type'=>'administrative_unrecovered_loss',
                        'basis'=>'customer_refund_at',
                        'cutoff_utc'=>$cutoff,
                        'refund_at'=>(string)$case['refund_at'],
                        'previous_state'=>(string)$case['state'],
                        'expected_reimbursement_amount_brl'=>(string)$case['expected_reimbursement_amount'],
                        'credit_confirmed_amount_brl'=>(string)$case['reconciled_credit_amount'],
                        'unrecovered_amount_brl'=>number_format($gapCents / 100,2,'.',''),
                        'external_amazon_case_unchanged'=>true,
                    ],
                    'evidence_sha256'=>null,
                ]);
                $updated = $this->persistence->cases->closeAgedUnreceivedCase(
                    $id,$case,$occurredAt,self::TERMINAL_REASON
                );
                if (!$updated) {
                    throw new RuntimeException('Age-90 administrative closure lost its case lock.');
                }
                $db->commit();
                $closed++;
                $cents += $gapCents;
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }

        return [
            'status'=>'OK',
            'scanned'=>count($ids),
            'closed'=>$closed,
            'deferred_active_outbox'=>$deferred,
            'unrecovered_brl'=>number_format($cents / 100,2,'.',''),
            'cutoff_at'=>$cutoff,
        ];
    }
}
