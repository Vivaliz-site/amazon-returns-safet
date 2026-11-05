<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/amazon-returns/Config.php';

function checkReadPriority(bool $ok, string $reason): void {
    if (!$ok) {
        fwrite(STDERR, "P0_SAFE_T_READ_PRIORITY=FAIL reason={$reason}\n");
        exit(1);
    }
}

checkReadPriority(method_exists(SvAmazonReturnsConfig::class, 'safeTReadPriorityCaseIds'), 'missing_bounded_read_priority');

$now = new DateTimeImmutable('2026-11-05T15:00:00Z');
$expected = [1263,1314,8803,13210,51429,101669,101670,101671,42471];
$ids = SvAmazonReturnsConfig::safeTReadPriorityCaseIds($now);
sort($ids);
sort($expected);
checkReadPriority($ids === $expected, 'owner_window_read_case_union_not_exact');
checkReadPriority(count($ids) === count(array_unique($ids)), 'overlapping_owner_window_case_not_deduplicated');

$afterFirstGrant = new DateTimeImmutable('2026-11-10T00:00:00Z');
$later = SvAmazonReturnsConfig::safeTReadPriorityCaseIds($afterFirstGrant);
sort($later);
checkReadPriority($later === [42471,51429,101669,101670,101671], 'expired_owner_window_priority_not_removed');
checkReadPriority(
    SvAmazonReturnsConfig::safeTReadPriorityCaseIds(new DateTimeImmutable('2026-12-01T00:00:00Z')) === [],
    'expired_owner_read_priority_not_empty'
);

$source = file_get_contents(dirname(__DIR__) . '/includes/amazon-returns/StatusBridgeService.php');
checkReadPriority(is_string($source), 'missing_read_bridge_source');
checkReadPriority(str_contains($source, 'SvAmazonReturnsConfig::safeTReadPriorityCaseIds($now)'), 'missing_owner_window_read_priority_call');
checkReadPriority(str_contains($source, '$this->p->outbox->claimBatch(1,$readKinds,$priorityCaseIds)'), 'missing_scoped_read_only_claim');
checkReadPriority(str_contains($source, '$this->p->outbox->claimBatch(1,$readKinds)'), 'missing_normal_read_fallback');
checkReadPriority(str_contains($source, "'SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ'"), 'read_kind_allowlist_changed');
checkReadPriority(!str_contains($source, "'SAFE_T_SUBMIT'"), 'read_priority_must_not_select_write_jobs');

$config = new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_ENABLED' => 'true',
    'AMAZON_RETURNS_MODE' => 'production',
    'AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH' => '1',
]);
checkReadPriority(
    array_sum(array_map(static fn(bool $allowed): int => $allowed ? 1 : 0, $config->writeFlags())) === 0,
    'priority_must_not_activate_external_writes'
);
echo "P0_SAFE_T_READ_PRIORITY=PASS mode=READ_ONLY \n";
