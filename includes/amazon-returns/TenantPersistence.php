<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/CaseRepository.php';
require_once __DIR__ . '/TenantEventStore.php';
require_once __DIR__ . '/EvidenceStore.php';
require_once __DIR__ . '/TenantOutbox.php';
require_once __DIR__ . '/SourceCursorStore.php';
require_once __DIR__ . '/PolicyRepository.php';
require_once __DIR__ . '/ReviewRepository.php';
require_once __DIR__ . '/LearnedRuleRepository.php';
require_once __DIR__ . '/RuleApplicationRepository.php';

final class SvAmazonTenantPersistence
{
    public readonly SvAmazonReturnCaseRepository $cases;
    public readonly SvAmazonTenantReturnEventStore $events;
    public readonly SvAmazonReturnEvidenceStore $evidence;
    public readonly SvAmazonTenantReturnsOutbox $outbox;
    public readonly SvAmazonSourceCursorStore $cursors;
    public readonly SvAmazonReturnPolicyRepository $policies;
    public readonly SvAmazonReviewRepository $reviews;
    public readonly SvAmazonLearnedRuleRepository $learnedRules;
    public readonly SvAmazonRuleApplicationRepository $ruleApplications;

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
        $this->reviews = new SvAmazonReviewRepository($db, $context);
        $this->learnedRules = new SvAmazonLearnedRuleRepository($db, $context);
        $this->ruleApplications = new SvAmazonRuleApplicationRepository($db, $context);
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
