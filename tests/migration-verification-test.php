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
foreach (['AMAZON_RETURNS_TENANT_SLUG','AMAZON_RETURNS_CONNECTION_KEY','tenant_id','amazon_connection_id','ownership_nulls','cross_tenant_children','case_connection_mismatches','cross_tenant_mismatch_count','tenant_count','connection_count','source_current_cases','target_current_cases','pre_migration_hash','post_migration_hash','ownership_hash','processing_jobs','AMAZON_RETURNS_EXPECTED_CASES','AMAZON_RETURNS_VERIFICATION_OUTPUT'] as $needle) {
    mvAssert(str_contains($source, $needle), 'Tenant-aware migration verification missing ' . $needle);
}
mvAssert(str_contains($source, 'information_schema.columns'), 'Verification must build a canonical common-column projection.');
mvAssert(str_contains($source, 'sha256sum'), 'Migration verification must compare content hashes.');
mvAssert(str_contains($source, 'COUNT(*)'), 'Migration verification must compare row counts.');
mvAssert(str_contains($source, 'RETURN_NOT_RECEIVED_D45_REFUND_V2'), 'Migration verification must assert the owner-approved D45 operational version.');
mvAssert(str_contains($source, "status='ACTIVE'"), 'Migration verification must resolve an active tenant connection.');
mvAssert(str_contains($source,'expected_cases="${AMAZON_RETURNS_EXPECTED_CASES:-37}"'),'Verification must default to the confirmed 37 cases.');
mvAssert(str_contains($source,"status='PROCESSING'"),'Verification must report stuck processing jobs.');
mvAssert(str_contains($source,'safet-submit-appeal-email-review-v1'),'Verification must assert the exact versioned write profile.');
mvAssert(str_contains($source,'SAFE_T_SUBMIT:1 SAFE_T_APPEAL:1'),'Verification must keep submit and appeal enabled.');
mvAssert(str_contains($source,'SAFE_T_EMAIL_REVIEW:1 SAFE_T_EMAIL_REPLY:0 SELLER_SUPPORT_OPEN:0 SELLER_SUPPORT_UPDATE:0'),'Verification must enable only second-stage review email while reply/support stay disabled.');
mvAssert(str_contains($source,'safe_t_email_review_write_enabled'),'Migration verification must emit the activated review-email flag.');
mvAssert(str_contains($source,'canonical_owner_rows'),'Verification must hash ordered target rows including owner IDs.');
mvAssert(!str_contains($source, 'cat "$env_file"'), 'Migration verification must not print environment secrets.');
foreach (['AMAZON_LWA_REFRESH_TOKEN','AMAZON_LWA_CLIENT_SECRET','GMAIL_OAUTH_REFRESH_TOKEN'] as $secret) {
    mvAssert(!str_contains($source, $secret), 'Migration verification must not inspect ' . $secret);
}

echo "migration-verification-test: OK\n";
