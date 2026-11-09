<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/RecoveryWindow.php';

/**
 * Reparacao EXPLICITA, unitária e transacional de encerramento administrativo
 * D+90 que contradiz recebimento físico confirmado pelo warehouse.
 *
 * Não é chamada por worker/scheduler e não envia SAFE-T, suporte ou crédito.
 * A execução em produção requer operador/lease, backup e grant externos.
 */
final class SvAmazonD90WarehouseClosureRepair
{
    public const ORIGINAL_REASON = 'RECOVERY_WINDOW_EXPIRED_D90';
    public const EVENT_TYPE = 'CASE_D90_WAREHOUSE_CLOSURE_CORRECTED';

    public function __construct(private SvAmazonTenantPersistence $persistence) {}

    /** @return array{status:string,case_id:int,reason?:string,event_id?:int} */
    public function repairOne(int $caseId, DateTimeImmutable $now): array
    {
        if ($caseId < 1) {
            throw new InvalidArgumentException('Case ID must be positive.');
        }

        $db = $this->persistence->db();
        $scope = $this->persistence->context();
        $utc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $db->beginTransaction();
        try {
            $case = $this->persistence->cases->lockD90WarehouseLossCandidate($caseId);
            if (!is_array($case)) {
                throw new RuntimeException('Case does not exist in authorized tenant/connection.');
            }

            if ($case['state'] !== 'CLOSED_LOSS'
                || $case['terminal_reason'] !== self::ORIGINAL_REASON
                || $case['physical_status'] !== 'RECEIVED_OK'
                || !SvAmazonRecoveryWindow::expired($case, $now)
                || !is_string($case['closed_at'])
                || $case['closed_at'] === ''
            ) {
                $db->commit();
                return ['status'=>'SKIPPED', 'case_id'=>$caseId, 'reason'=>'preconditions_not_met'];
            }

            // Prova da mesma conexao/tenant: somente WAREHOUSE, nunca tracking.
            $receiptEventId = $this->persistence->cases->warehousePhysicalReceiptEventId($caseId);
            if ($receiptEventId === null) {
                $db->commit();
                return ['status'=>'SKIPPED', 'case_id'=>$caseId, 'reason'=>'warehouse_proof_absent'];
            }

            if ($this->persistence->cases->hasAdministrativeD90ClosureEvent($caseId)) {
                $db->commit();
                return ['status'=>'SKIPPED', 'case_id'=>$caseId, 'reason'=>'original_audit_present'];
            }

            if ($this->persistence->cases->hasPendingAgeCloseOutbox($caseId)) {
                $db->commit();
                return ['status'=>'SKIPPED', 'case_id'=>$caseId, 'reason'=>'active_outbox'];
            }

            if (!$this->persistence->cases->compensateD90WarehouseClosure($caseId, $case, $utc)) {
                throw new RuntimeException('Case changed during D90 warehouse reconciliation.');
            }

            $eventId = $this->persistence->events->append([
                'case_id'=>$caseId,
                'event_type'=>self::EVENT_TYPE,
                'source'=>'INTERNAL',
                'source_event_id'=>'D90_WAREHOUSE_REPAIR_' . $caseId,
                'idempotency_key'=>SvAmazonTenantReturnEventStore::deterministicKey(
                    'd90-warehouse-repair-v1', $scope->scopeKey(), (string)$caseId
                ),
                'occurred_at'=>$utc,
                'payload'=>[
                    'classification'=>'retrospective_integrity_reconciliation',
                    'previous_state'=>(string)$case['state'],
                    'previous_terminal_reason'=>(string)$case['terminal_reason'],
                    'previous_closed_at'=>(string)$case['closed_at'],
                    'new_state'=>'RECEIVED_OK',
                    'new_terminal_reason'=>'WAREHOUSE_RECEIVED_ADMIN_FINAL_D90',
                    'administratively_closed_no_reopen'=>true,
                    'warehouse_physical_receipt_confirmed'=>true,
                    'warehouse_receipt_event_id'=>$receiptEventId,
                    'financial_amounts_unchanged'=>true,
                    'external_claims_unchanged'=>true,
                    'no_new_recovery_attempt'=>true,
                ],
                'evidence_sha256'=>null,
            ]);

            $db->commit();
            return ['status'=>'CORRECTED', 'case_id'=>$caseId, 'event_id'=>$eventId];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
