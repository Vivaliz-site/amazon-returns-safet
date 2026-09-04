<?php
declare(strict_types=1);

function mvAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$path = __DIR__ . '/../scripts/verify-migration.sh';
mvAssert(is_file($path), 'Migration verification script must exist.');
$source = (string)file_get_contents($path);

foreach (['amazon_return_cases','amazon_return_events','amazon_return_outbox','amazon_return_dead_letters','amazon_return_evidence','amazon_return_policies','amazon_return_source_cursors','amazon_return_overrides'] as $table) {
    mvAssert(str_contains($source, $table), 'Migration verification must cover ' . $table);
}
foreach (['AMAZON_RETURNS_TENANT_SLUG','AMAZON_RETURNS_CONNECTION_KEY','tenant_id','amazon_connection_id','ownership_nulls','cross_tenant_children','case_connection_mismatches'] as $needle) {
    mvAssert(str_contains($source, $needle), 'Tenant-aware migration verification missing ' . $needle);
}
mvAssert(str_contains($source, 'information_schema.columns'), 'Verification must build a canonical common-column projection.');
mvAssert(str_contains($source, 'sha256sum'), 'Migration verification must compare content hashes.');
mvAssert(str_contains($source, 'COUNT(*)'), 'Migration verification must compare row counts.');
mvAssert(str_contains($source, 'eligibility_days <> 75'), 'Migration verification must assert D+75.');
mvAssert(str_contains($source, "status='ACTIVE'"), 'Migration verification must resolve an active tenant connection.');
mvAssert(!str_contains($source, 'cat "$env_file"'), 'Migration verification must not print environment secrets.');
foreach (['AMAZON_LWA_REFRESH_TOKEN','AMAZON_LWA_CLIENT_SECRET','GMAIL_OAUTH_REFRESH_TOKEN'] as $secret) {
    mvAssert(!str_contains($source, $secret), 'Migration verification must not inspect ' . $secret);
}

echo "migration-verification-test: OK\n";
