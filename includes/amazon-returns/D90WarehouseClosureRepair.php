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
        $driver = (string) $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $params = [
            ':case_id' => $caseId,
            ':tenant_id' => $scope->tenantId(),
            ':amazon_connection_id' => $scope->amazonConnectionId(),
        ];

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT c.* FROM amazon_return_cases c '
                . 'WHERE c.id=:case_id AND c.tenant_id=:tenant_id '
                . 'AND c.amazon_connection_id=:amazon_connection_id LIMIT 1'
                . ($driver === 'mysql' ? ' FOR UPDATE' : '')
            );
            $stmt->execute($params);
            $case = $stmt->fetch(PDO::FETCH_ASSOC);
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

            // A mesma conexao e tenant precisam possuir prova de recebimento
            // originada do WAREHOUSE, nunca apenas tracking externo.
            $receipt = $db->prepare(
                "SELECT id FROM amazon_return_events "
                . "WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id "
                . "AND case_id=:case_id AND event_type='PHYSICAL_RECEIVED' "
                . "AND source='WAREHOUSE' LIMIT 1"
            );
            $receipt->execute($params);
            $receiptEventId = $receipt->fetchColumn();
            if ($receiptEventId === false) {
                $db->commit();
                return ['status'=>'SKIPPED', 'case_id'=>$caseId, 'reason'=>'warehouse_proof_absent'];
            }

            // Encerramentos legitimos com evento atomico anterior exigem
            // revisao individual, nao compensacao automatica.
            $original = $db->prepare(
                "SELECT id FROM amazon_return_events "
                . "WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id "
                . "AND case_id=:case_id AND event_type='CASE_CLOSED_ADMIN_90D' LIMIT 1"
            );
            $original->execute($params);
            if ($original->fetchColumn() !== false) {
                $db->commit();
                return ['status'=>'SKIPPED', 'case_id'=>$caseId, 'reason'=>'original_audit_present'];
            }

            if ($this->persistence->cases->hasPendingAgeCloseOutbox($caseId)) {
                $db->commit();
                return ['status'=>'SKIPPED', 'case_id'=>$caseId, 'reason'=>'active_outbox'];
            }

            // CAS de estado, tenant, conexao, fechamento e prova WAREHOUSE.
            $update = $db->prepare(
                "UPDATE amazon_return_cases SET state='RECEIVED_OK',"
                . "terminal_reason=NULL,closed_at=NULL,next_action_at=NULL,updated_at=:updated_at "
                . "WHERE id=:case_id AND tenant_id=:tenant_id "
                . "AND amazon_connection_id=:amazon_connection_id "
                . "AND state='CLOSED_LOSS' AND terminal_reason='RECOVERY_WINDOW_EXPIRED_D90' "
                . "AND physical_status='RECEIVED_OK' AND closed_at=:prior_closed_at "
                . "AND EXISTS (SELECT 1 FROM amazon_return_events e "
                . "WHERE e.tenant_id=amazon_return_cases.tenant_id "
                . "AND e.amazon_connection_id=amazon_return_cases.amazon_connection_id "
                . "AND e.case_id=amazon_return_cases.id "
                . "AND e.event_type='PHYSICAL_RECEIVED' AND e.source='WAREHOUSE')"
            );
            $update->execute($params + [
                ':updated_at'=>$utc,
                ':prior_closed_at'=>(string)$case['closed_at'],
            ]);
            if ($update->rowCount() !== 1) {
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
                    'warehouse_physical_receipt_confirmed'=>true,
                    'warehouse_receipt_event_id'=>(int)$receiptEventId,
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
