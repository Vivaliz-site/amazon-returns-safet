<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/PolicySeeder.php';
require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/BridgeLiveness.php';

final class SvAmazonReturnsRuntime
{
    private static ?string $nextKnownActionAt=null;

    /** @return array<string,int> */
    public static function cadences(): array
    {
        return [
            'gmail'=>43200,
            'gmail_refund_reconciliation'=>43200,
            'scheduler'=>43200,
            'review_operations'=>14400,
            'seller_central'=>43200,
            'financial'=>43200,
            'sp_api'=>43200,
            'returns_report'=>43200,
            'health'=>900,
            'policy_monitor'=>43200,
        ];
    }

    public static function knownActionDue(array $state,DateTimeImmutable $now): bool
    {
        $wake=self::timestamp($state['next_known_action_at'] ?? null);
        if($wake===null)return false;
        $now=$now->setTimezone(new DateTimeZone('UTC'));
        return $wake<=$now;
    }

    /** @param list<mixed> $candidates */
    public static function nextKnownActionAt(array $candidates,DateTimeImmutable $now): ?string
    {
        $now=$now->setTimezone(new DateTimeZone('UTC'));
        $next=null;
        foreach($candidates as $candidate){
            $when=self::timestamp($candidate);
            if($when===null || $when<=$now)continue;
            if($next===null || $when<$next)$next=$when;
        }
        return $next?->format(DATE_ATOM);
    }

    private static function timestamp(mixed $raw): ?DateTimeImmutable
    {
        if(!is_string($raw) || trim($raw)==='')return null;
        try{
            return (new DateTimeImmutable(trim($raw),new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        }catch(Throwable){
            return null;
        }
    }

    private static function refreshNextKnownActionAt(PDO $db,SvAmazonTenantContext $context): void
    {
        self::$nextKnownActionAt=null;
        $stmt=$db->prepare(
            'SELECT MIN(next_action_at) FROM amazon_return_cases '
            . 'WHERE tenant_id=:tenant_id AND amazon_connection_id=:amazon_connection_id '
            . 'AND closed_at IS NULL AND next_action_at IS NOT NULL '
            . 'AND (next_action_at>UTC_TIMESTAMP() OR updated_at<next_action_at)'
        );
        $stmt->execute([
            ':tenant_id'=>$context->tenantId(),
            ':amazon_connection_id'=>$context->amazonConnectionId(),
        ]);
        $value=$stmt->fetchColumn();
        $when=self::timestamp(is_string($value)?$value:null);
        if($when!==null)self::$nextKnownActionAt=$when->format(DATE_ATOM);
    }

    public static function decisionStackRevision(): string
    {
        $files=[
            __FILE__,
            __DIR__.'/PolicyEngine.php',
            __DIR__.'/Projector.php',
            __DIR__.'/SafeTDecisionEngine.php',
            __DIR__.'/DecisionCoordinator.php',
            __DIR__.'/ReturnActionRouter.php',
            __DIR__.'/SafeTStatusService.php',
            dirname(__DIR__,2).'/scripts/amazon-returns/safe-t-status-parser.mjs',
            dirname(__DIR__,2).'/workers/amazon-returns/scheduler.php',
        ];
        $parts=[];
        foreach($files as $file){
            $hash=@hash_file('sha256',$file);
            if(!is_string($hash) || $hash===''){
                throw new RuntimeException('Unable to fingerprint Amazon returns decision stack.');
            }
            $parts[]=basename($file).':'.$hash;
        }
        return hash('sha256',implode('|',$parts));
    }

    public static function financialRefreshContinuationRequired(array $results): bool
    {
        if((int)($results['scheduler']['financial_checks_requested']??0)<1)return false;
        if(!isset($results['sp_api']))return true;
        return ($results['sp_api']['rotation_has_more']??false)===true;
    }

    public static function gmailEvidenceRevision(): string
    {
        $files=[
            __DIR__.'/GmailParser.php',
            __DIR__.'/GmailEventSink.php',
        ];
        $parts=[];
        foreach($files as $file){
            $hash=@hash_file('sha256',$file);
            if(!is_string($hash) || $hash===''){
                throw new RuntimeException('Unable to fingerprint Gmail evidence stack.');
            }
            $parts[]=basename($file).':'.$hash;
        }
        return hash('sha256',implode('|',$parts));
    }

    /** @return list<string> */
    public static function dueTasks(
        array $state,
        DateTimeImmutable $now,
        ?string $decisionStackRevision=null,
        ?string $gmailEvidenceRevision=null
    ): array {
        $now=$now->setTimezone(new DateTimeZone('UTC'));
        $due=['bootstrap'];
        foreach(self::cadences() as $task=>$seconds){
            $last=$state[$task] ?? null;
            if(!is_string($last) || $last===''){
                $due[]=$task;
                continue;
            }
            try{
                $when=(new DateTimeImmutable($last))->setTimezone(new DateTimeZone('UTC'));
            }catch(Throwable){
                $due[]=$task;
                continue;
            }
            if($now->getTimestamp()-$when->getTimestamp()>=$seconds)$due[]=$task;
        }
        $wakeState=$state;
        if(self::$nextKnownActionAt!==null)$wakeState['next_known_action_at']=self::$nextKnownActionAt;
        if(self::knownActionDue($wakeState,$now)){
            $due=[...$due,'known_action_wake','gmail','sp_api','financial','scheduler','seller_central'];
        }
        if(
            is_string($decisionStackRevision)
            && $decisionStackRevision!==''
            && ($state['decision_stack_revision'] ?? null)!==$decisionStackRevision
        ){
            $due[]='scheduler';
        }
        if(
            is_string($gmailEvidenceRevision)
            && $gmailEvidenceRevision!==''
            && ($state['gmail_evidence_revision'] ?? null)!==$gmailEvidenceRevision
        ){
            $due[]='gmail_refund_reconciliation';
        }
        return array_values(array_unique($due));
    }

    /**
     * Refresh read-side evidence before deciding, then execute newly queued writes and
     * human-review reminders only after deterministic decisions have been re-evaluated.
     * A known-date wake gets a second Gmail pass after the scheduler so email writes
     * created by that decision are delivered in the same cycle.
     *
     * @param list<string> $due
     * @return list<string>
     */
    public static function decisionSafeOrder(array $due): array
    {
        $knownActionWake=in_array('known_action_wake',$due,true);
        $due=array_values(array_unique(array_filter(
            $due,
            static fn(mixed $task):bool=>is_string($task) && $task!=='' && $task!=='known_action_wake'
        )));
        $evidenceOrder=[
            'gmail',
            'gmail_refund_reconciliation',
            'sp_api',
            'returns_report',
            'financial',
        ];
        $hasEvidence=false;
        foreach($evidenceOrder as $task){
            if(in_array($task,$due,true)){
                $hasEvidence=true;
                break;
            }
        }
        if($hasEvidence && !in_array('scheduler',$due,true))$due[]='scheduler';

        $ordered=[];
        $append=static function(string $task) use (&$ordered,$due):void {
            if(in_array($task,$due,true) && !in_array($task,$ordered,true))$ordered[]=$task;
        };
        $append('bootstrap');
        foreach($evidenceOrder as $task)$append($task);
        $append('scheduler');
        if($knownActionWake && in_array('gmail',$due,true))$ordered[]='gmail';
        $append('seller_central');
        $append('review_operations');
        foreach($due as $task)$append($task);
        return $ordered;
    }

    /** @return array<string,mixed> */
    public static function bootstrap(PDO $db, SvAmazonTenantContext $context): array
    {
        SvAmazonReturnsSchema::ensure($db);
        $p=SvAmazonTenantPersistence::create($db,$context);
        $policySeeds=SvAmazonReturnPolicySeeder::ensure($p->policies);
        $policyAudit=$policySeeds>0 ? SvAmazonReturnPolicySeeder::auditDefinitions($p->policies->allActive()) : ['valid'=>true,'policy_key'=>null];
        if(!$policyAudit['valid'])throw new RuntimeException('Active operational policy does not match approved opening rule.');
        self::refreshNextKnownActionAt($db,$context);
        return [
            'status'=>'OK',
            'tenant_id'=>$context->tenantId(),
            'amazon_connection_id'=>$context->amazonConnectionId(),
            'schema_tables'=>count(SvAmazonReturnsSchema::statements()),
            'policy_seeds'=>$policySeeds,
            'policy_audit'=>$policyAudit,
            'next_known_action_at'=>self::$nextKnownActionAt,
        ];
    }

    /** @return array<string,mixed> */
    public static function health(
        SvAmazonTenantPersistence $p,
        SvAmazonReturnsConfig $config
    ): array {
        $db=$p->db();
        $tables=(int)$db->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema=DATABASE() AND table_name LIKE 'amazon_return_%'"
        )?->fetchColumn();
        $reviewNotifyEmail=trim($config->get('AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL'));
        $reviewNotificationReady=filter_var($reviewNotifyEmail,FILTER_VALIDATE_EMAIL)!==false;
        $readiness=$config->readiness();
        $bridgeRequired=$config->enabled() && (($readiness['seller_central_bridge']['ready'] ?? false)===true);
        $primaryStatusWorker=trim($config->get(
            'SELLER_CENTRAL_PRIMARY_STATUS_WORKER_ID','vm-a1-safe-t-status'
        ));
        $browserLiveness=SvAmazonBridgeLiveness::evaluate(
            $p->cursors->load('SELLER_CENTRAL','browser_auth'),
            new DateTimeImmutable('now',new DateTimeZone('UTC')),
            $bridgeRequired,
            $p->cursors->load('SELLER_CENTRAL','read_process_heartbeat'),
            $primaryStatusWorker
        );
        $healthStatus=($browserLiveness['status'] ?? '')==='DEGRADED' ? 'DEGRADED' : 'OK';
        return [
            'status'=>$healthStatus,
            'tenant_id'=>$p->context()->tenantId(),
            'amazon_connection_id'=>$p->context()->amazonConnectionId(),
            'tables'=>$tables,
            'cases'=>$p->cases->countAll(),
            'pending_outbox'=>$p->outbox->countPendingProcessing(),
            'dead_letters'=>$p->outbox->countDeadLetters(),
            'pending_reviews'=>$p->reviews->countOpen(),
            'rule_conflicts'=>$p->reviews->countOpenByReason('LEARNED_RULE_CONFLICT'),
            'rule_applications'=>$p->ruleApplications->countAll(),
            'ai_suggestion_failures'=>$p->reviews->countAiFailures(),
            'review_ai_ready'=>$config->reviewAiReady(),
            'review_notification_ready'=>$reviewNotificationReady,
            'learned_rule_execution_enabled'=>$config->learnedRuleExecutionEnabled(),
            'review_memory'=>[
                'pending_reviews'=>$p->reviews->countOpen(),
                'rule_conflicts'=>$p->reviews->countOpenByReason('LEARNED_RULE_CONFLICT'),
                'rule_applications'=>$p->ruleApplications->countAll(),
                'ai_suggestion_failures'=>$p->reviews->countAiFailures(),
                'review_ai_ready'=>$config->reviewAiReady(),
                'review_notification_ready'=>$reviewNotificationReady,
                'learned_rule_execution_enabled'=>$config->learnedRuleExecutionEnabled(),
            ],
            'source_cursors'=>$p->cursors->count(),
            'mode'=>$config->mode(),
            'enabled'=>$config->enabled(),
            'readiness'=>$readiness,
            'seller_central_browser'=>$browserLiveness,
            'write_flags'=>$config->writeFlags(),
        ];
    }
}
