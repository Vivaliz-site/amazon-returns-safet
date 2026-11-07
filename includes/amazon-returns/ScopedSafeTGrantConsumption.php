<?php
declare(strict_types=1);

/**
 * Persistently reserves an owner-approved SAFE-T grant exactly once.
 *
 * This class is not an operator and never enables external writes. It must be
 * called by a certified adapter AFTER owner + provider preflight, and BEFORE
 * any irreversible submission. Its auto-increment identifier is a monotonic
 * fencing candidate, not proof of exclusive ownership of the existing worker.
 */
final class SvAmazonScopedSafeTGrantConsumption
{
    private const ALLOWED_GRANTS = [3556, 3673];

    public function __construct(private PDO $db) {}

    /**
     * Returns a positive fencing token only for the FIRST reservation. No
     * process, restart or second grant consumer can reserve the same tuple.
     *
     * @return int|null null means an existing reservation or consumption.
     */
    public function reserve(int $grantNumber, int $tenantId, int $connectionId): ?int
    {
        $this->assertScope($grantNumber, $tenantId, $connectionId);

        try {
            $statement = $this->db->prepare(
                "INSERT INTO amazon_return_scoped_grant_consumptions
                  (tenant_id,amazon_connection_id,grant_number,status,created_at)
                 VALUES (:tenant,:connection,:grant,'RESERVED',CURRENT_TIMESTAMP)"
            );
            $statement->execute([
                ':tenant'=>$tenantId,
                ':connection'=>$connectionId,
                ':grant'=>$grantNumber,
            ]);
        } catch (PDOException $e) {
            // Only classify a UNIQUE-KEY conflict as an existing reservation.
            // A missing table, FK error, driver failure, or permissions error
            // must surface rather than pretending the grant is consumed.
            if ($e->getCode() !== '23000') throw $e;
            $probe = $this->db->prepare(
                'SELECT id FROM amazon_return_scoped_grant_consumptions
                 WHERE tenant_id=:tenant AND amazon_connection_id=:connection
                   AND grant_number=:grant LIMIT 1'
            );
            $probe->execute([
                ':tenant'=>$tenantId, ':connection'=>$connectionId,
                ':grant'=>$grantNumber,
            ]);
            if (!$probe->fetchColumn()) throw $e;
            return null;
        }
        $fencing=(int)$this->db->lastInsertId();
        if ($fencing < 1) throw new RuntimeException('SCOPED_GRANT_FENCING_NOT_PERSISTED');
        return $fencing;
    }

    /**
     * Irreversible CAS: consumed once before the provider click.
     * A consumed grant can NEVER become RESERVED again.
     */
    public function consume(
        int $grantNumber, int $tenantId, int $connectionId, int $fencing
    ): bool {
        $this->assertScope($grantNumber, $tenantId, $connectionId);
        if ($fencing < 1) throw new InvalidArgumentException('Invalid fencing token');
        $statement=$this->db->prepare(
            "UPDATE amazon_return_scoped_grant_consumptions
             SET status='CONSUMED',consumed_at=CURRENT_TIMESTAMP
             WHERE id=:fencing AND tenant_id=:tenant
               AND amazon_connection_id=:connection AND grant_number=:grant
               AND status='RESERVED' AND consumed_at IS NULL"
        );
        $statement->execute([
            ':fencing'=>$fencing, ':tenant'=>$tenantId,
            ':connection'=>$connectionId, ':grant'=>$grantNumber,
        ]);
        return $statement->rowCount()===1;
    }

    /** No retry or implicit release: keep evidence for manual reconciliation. */
    public function quarantine(
        int $grantNumber, int $tenantId, int $connectionId, int $fencing
    ): bool {
        $this->assertScope($grantNumber, $tenantId, $connectionId);
        if ($fencing < 1) return false;
        $statement=$this->db->prepare(
            "UPDATE amazon_return_scoped_grant_consumptions
             SET status='QUARANTINED',quarantined_at=CURRENT_TIMESTAMP
             WHERE id=:fencing AND tenant_id=:tenant
               AND amazon_connection_id=:connection AND grant_number=:grant
               AND status IN ('RESERVED','CONSUMED')"
        );
        $statement->execute([
            ':fencing'=>$fencing, ':tenant'=>$tenantId,
            ':connection'=>$connectionId, ':grant'=>$grantNumber,
        ]);
        return $statement->rowCount()===1;
    }

    /** Only non-sensitive fields are returned. */
    public function readStatus(int $grantNumber, int $tenantId, int $connectionId): ?array
    {
        $this->assertScope($grantNumber, $tenantId, $connectionId);
        $statement=$this->db->prepare(
            'SELECT id,status,consumed_at,quarantined_at
             FROM amazon_return_scoped_grant_consumptions
             WHERE tenant_id=:tenant AND amazon_connection_id=:connection
               AND grant_number=:grant LIMIT 1'
        );
        $statement->execute([
            ':tenant'=>$tenantId, ':connection'=>$connectionId,
            ':grant'=>$grantNumber,
        ]);
        $data=$statement->fetch(PDO::FETCH_ASSOC);
        return is_array($data)?[
            'fencing'=>(int)$data['id'],
            'status'=>(string)$data['status'],
            'consumed'=>($data['consumed_at']!==null),
            'quarantined'=>($data['quarantined_at']!==null),
        ]:null;
    }

    private function assertScope(int $grantNumber, int $tenantId, int $connectionId): void
    {
        if (!in_array($grantNumber, self::ALLOWED_GRANTS, true)
            || $tenantId!==1 || $connectionId!==1) {
            throw new InvalidArgumentException('SCOPED_GRANT_SCOPE_DENIED');
        }
    }
}
