<?php
declare(strict_types=1);

function cssAssert(bool $condition,string $message):void
{
    if(!$condition)throw new RuntimeException($message);
}

$src=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/CaseRepository.php');
cssAssert(
    !str_contains($src,"c.current_action='SAFE_T_APPEAL'"),
    'Summary SQL cannot query derived current_action from amazon_return_cases.'
);
cssAssert(
    str_contains($src,"c.refund_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 90 DAY)"),
    'Expired-treatment gate must ignore refunds older than 90 days.'
);
cssAssert(str_contains($src,"o.kind='SAFE_T_APPEAL'"),'Expired-treatment gate must account for appeal writes.');
cssAssert(str_contains($src,"o.status IN ('PENDING','PROCESSING','SUCCEEDED')"),'Queued, running, or completed appeals must not be reported as untreated.');
cssAssert(str_contains($src,'o.tenant_id=c.tenant_id'),'Appeal-write exclusion must remain tenant scoped.');
cssAssert(str_contains($src,'o.amazon_connection_id=c.amazon_connection_id'),'Appeal-write exclusion must remain connection scoped.');
cssAssert(str_contains($src,'o.case_id=c.id'),'Appeal-write exclusion must remain case scoped.');

echo "cockpit-summary-schema-contract-test: OK\n";
