<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/Database.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/TenantRegistry.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/TenantPersistence.php';
require_once dirname(__DIR__, 2) . '/includes/amazon-returns/RecoveryWindow.php';

/**
 * Reconciles the exact predicates of the existing D90WarehouseClosureRepair
 * against a consistent READ ONLY snapshot. No rows, locks, flags, claims,
 * support requests, credits or worker state are changed by this command.
 *
 * The counts are evidence for an owner-scoped repair, NOT authorization to
 * execute that repair or proof of independent ERP/Finances reconciliation.
 *
 * @return array<string,mixed>
 */
function sv_amazon_d90_warehouse_preview(
    PDO $db,
    SvAmazonTenantContext $scope,
    DateTimeImmutable $now
): array {
    if ($db->inTransaction()) {
        throw new LogicException('D90 preflight requires its own read-only transaction.');
    }
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $db->exec('SET TRANSACTION READ ONLY');
    }
    $db->beginTransaction();
    try {
        $p = SvAmazonTenantPersistence::create($db, $scope);
        // Tenant-scoped SELECTs live in canonical repository classes; the
        // script never issues table SQL outside those repository contracts.
        $cases = $p->cases->d90WarehouseClosurePreviewCandidates(500);
        if (count($cases) > 500) {
            throw new RuntimeException('D90 preview exceeds supported batch; no partial verdict.');
        }
        $counts = [
            'candidate_rows'=>count($cases),
            'warehouse_proof_valid'=>0,
            'warehouse_proof_missing_or_voided'=>0,
            'original_admin_closure_present'=>0,
            'active_outbox'=>0,
            'not_yet_d90'=>0,
            'closed_timestamp_missing'=>0,
            'eligible_by_data_only'=>0,
            'financial_credit_positive'=>0,
        ];
        $fingerprints = [];
        foreach ($cases as $case) {
            $caseId = (int)($case['id'] ?? 0);
            if ($caseId < 1) throw new RuntimeException('Invalid scoped candidate identity.');
            $fingerprints[] = $caseId . ':' . (string)($case['updated_at'] ?? '');
            if ((float)($case['reconciled_credit_amount'] ?? 0) > 0) {
                $counts['financial_credit_positive']++;
            }
            // Source WAREHOUSE, excluding later confirmed receipt voids.
            if ($p->cases->warehousePhysicalReceiptEventId($caseId) === null) {
                $counts['warehouse_proof_missing_or_voided']++;
                continue;
            }
            $counts['warehouse_proof_valid']++;
            if ($p->cases->hasAdministrativeD90ClosureEvent($caseId)) {
                $counts['original_admin_closure_present']++;
                continue;
            }
            if ($p->outbox->hasPendingProcessingForCase($caseId)) {
                $counts['active_outbox']++;
                continue;
            }
            if (!SvAmazonRecoveryWindow::expired($case, $now)) {
                $counts['not_yet_d90']++;
                continue;
            }
            if (!is_string($case['closed_at'] ?? null) || $case['closed_at'] === '') {
                $counts['closed_timestamp_missing']++;
                continue;
            }
            $counts['eligible_by_data_only']++;
        }
        $db->rollBack();
        return [
            'verdict'=>'READ_ONLY_PREVIEW',
            'scope'=>$scope->scopeKey(),
            'observed_at'=>$now->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'counts'=>$counts,
            'candidate_version_set_sha256'=>hash('sha256', implode('|', $fingerprints)),
            'database_mutated'=>false,
            'external_requests_sent'=>false,
            'operator_grant_backup_lease_verified'=>false,
            'repair_performed'=>false,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function sv_amazon_d90_warehouse_preview_cli(array $argv): int
{
    if (PHP_SAPI !== 'cli') return 2;
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--(tenant|connection)=([1-9][0-9]{0,8})$/D', (string)$argument, $match)
            || isset($options[$match[1]])) {
            fwrite(STDERR, "Use: php d90-warehouse-closure-preflight.php --tenant=N --connection=N\n");
            return 2;
        }
        $options[$match[1]] = (int)$match[2];
    }
    if (count($options) !== 2) {
        fwrite(STDERR, "Explicit tenant and connection are required.\n");
        return 2;
    }
    try {
        $db = amazon_returns_require_pdo();
        $configured = SvAmazonTenantRegistry::resolveCurrent($db, new SvAmazonReturnsConfig());
        if ($configured->tenantId() !== $options['tenant']
            || $configured->amazonConnectionId() !== $options['connection']) {
            throw new RuntimeException('Requested tenant/connection differs from the active runtime.');
        }
        $result = sv_amazon_d90_warehouse_preview(
            $db, $configured, new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
        echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        return 0;
    } catch (Throwable $e) {
        fwrite(STDERR, "D90_WAREHOUSE_PREFLIGHT=FAILED class=" . $e::class . PHP_EOL);
        return 3;
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(sv_amazon_d90_warehouse_preview_cli($argv ?? []));
}
