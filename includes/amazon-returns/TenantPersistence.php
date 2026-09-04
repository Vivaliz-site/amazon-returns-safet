<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/CaseRepository.php';
require_once __DIR__ . '/TenantEventStore.php';
require_once __DIR__ . '/EvidenceStore.php';
require_once __DIR__ . '/TenantOutbox.php';
require_once __DIR__ . '/SourceCursorStore.php';
require_once __DIR__ . '/PolicyRepository.php';

final class SvAmazonTenantPersistence
{
    public readonly SvAmazonReturnCaseRepository $cases;
    public readonly SvAmazonTenantReturnEventStore $events;
    public readonly SvAmazonReturnEvidenceStore $evidence;
    public readonly SvAmazonTenantReturnsOutbox $outbox;
    public readonly SvAmazonSourceCursorStore $cursors;
    public readonly SvAmazonReturnPolicyRepository $policies;

    private function __construct(
        private readonly PDO $db,
        private readonly SvAmazonTenantContext $context
    ) {
        $this->cases = new SvAmazonReturnCaseRepository($db, $context);
        $this->events = new SvAmazonTenantReturnEventStore($db, $context);
        $this->evidence = new SvAmazonReturnEvidenceStore($db, $context);
        $this->outbox = new SvAmazonTenantReturnsOutbox($db, $context);
        $this->cursors = new SvAmazonSourceCursorStore($db, $context);
        $this->policies = new SvAmazonReturnPolicyRepository($db, $context);
    }

    public static function create(PDO $db, SvAmazonTenantContext $context): self
    {
        return new self($db, $context);
    }

    public function db(): PDO
    {
        return $this->db;
    }

    public function context(): SvAmazonTenantContext
    {
        return $this->context;
    }
}
