<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/PolicySeeder.php';
require_once __DIR__ . '/TenantContext.php';
require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/BridgeLiveness.php';
require_once __DIR__ . '/BusinessHealth.php';
require_once __DIR__ . '/OperationalHealth.php';

final class SvAmazonReturnsRuntime
{
    /** @var list<array<string,mixed>> */
    private static array $knownActionCases=[];

    /** @return array<string,int> */
    public static function writeConfigurationChangeTasks(): array
    {
        return ['scheduler','erp_sales_returns'];
    }

    public static function cadences(): array
    {
        return [
            'gmail'=>43200,
            'gmail_refund_reconciliation'=>43200,
            'scheduler'=>43200,
            'review_operations'=>7200,
            'seller_central'=>43200,
            'financial'=>43200,
            'sp_api'=>43200,
            'returns_report'=>43200,
            'erp_sales_returns'=>43200,
            'health'=>900,
            'policy_monitor'=>43200,
        ];
    }

    public static function knownActionDue(array $state,DateTimeImmutable $now): bool
    {
        $wake=self::timestamp($state['next_known_action_at'] ?? null);
        if($wake===null)return false;
        $now=$now->setTimezone(new DateTimeZone('UTC'));
        if($wake>$now)return false;
        $lastScheduler=self::timestamp($state['scheduler'] ?? null);
        return $lastScheduler===null || $lastScheduler<$wake;
    }

    /** @param list<array<string,mixed>> $cases */
    public static function nextKnownWakeAt(array $cases,?DateTimeImmutable $after=null): ?string
    {
        $after=$after?->setTimezone(new DateTimeZone('UTC'));
        $next=null;
        foreach($cases as $case){
            if(!is_array($case) || ($case['closed_at'] ?? null)!==null)continue;
            $when=self::timestamp($case['next_action_at'] ?? null);
            if($when===null || ($after!==null && $when<=$after))continue;
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

    private static function refreshKnownActionCases(SvAmazonTenantPersistence $p): void
    {
        self::$knownActionCases=$p->cases->openCases(1000);
    }

    public static function financialPipelineRevision(): string
    {
        $files=[
            __DIR__.'/FinancialRefresh.php',
            __DIR__.'/FinancialRevalidation.php',
            __DIR__.'/FinancialCheckEvidence.php',
            __DIR__.'/FinancialReconciler.php',
            __DIR__.'/SpApi.php',
            __DIR__.'/SpApiEventSink.php',
            dirname(__DIR__,2).'/workers/amazon-returns/reconcile.php',
            dirname(__DIR__,2).'/workers/amazon-returns/scheduler.php',
            dirname(__DIR__,2).'/workers/amazon-returns/daemon.php',
        ];
        $parts=[];
        foreach($files as $file){
            $hash=@hash_file('sha256',$file);
            if(!is_string($hash) || $hash===''){
                throw new RuntimeException('Unable to fingerprint Amazon returns financial pipeline.');
            }
            $parts[]=basename($file).':'.$hash;
        }
        return hash('sha256',implode('|',$parts));
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
        $parts[]='financial_pipeline:'.self::financialPipelineRevision();
        return hash('sha256',implode('|',$parts));
    }

    public static function outboxStackRevision(): string
    {
        $file=__DIR__.'/TenantOutbox.php';
        $hash=@hash_file('sha256',$file);
        if(!is_string($hash) || $hash==='')throw new RuntimeException('Unable to fingerprint Amazon returns outbox stack.');
        return $hash;
    }

    /** @param array<string,mixed> $result */
    public static function sellerCentralCycleAcknowledgesOutboxRevision(array $result): bool
    {
        $status=strtoupper(trim((string)($result['status']??'')));
        return in_array($status,['OK','REMOTE_POLLING'],true);
    }

    public static function financialRefreshContinuationRequired(array $results): bool
    {
        $requested=(int)($results['scheduler']['financial_checks_requested']??0);
        $rotationIncomplete=($results['sp_api']['rotation_has_more']??false)===true;
        $financialRotationIncomplete=($results['financial']['rotation_has_more']??false)===true;
        $cycleFailures=max(0,(int)($results['sp_api']['cycle_failures']??0));
        $financialBlocked=($results['financial']['reason']??'')==='FINANCIAL_REFRESH_NOT_ACCEPTED';
        if($financialBlocked && ($rotationIncomplete || $cycleFailures>0))return true;
        if($rotationIncomplete || $financialRotationIncomplete)return true;
        if($requested<1)return false;
        if(!isset($results['sp_api']))return true;
        return false;
    }

    public static function financialRefreshRetryDelaySeconds(array $results): ?int
    {
        $rotationIncomplete=($results['sp_api']['rotation_has_more']??false)===true;
        $cycleFailures=max(0,(int)($results['sp_api']['cycle_failures']??0));
        $financialBlocked=($results['financial']['reason']??'')==='FINANCIAL_REFRESH_NOT_ACCEPTED';
        return $financialBlocked && !$rotationIncomplete && $cycleFailures>0 ? 1800 : null;
    }

    public static function gmailClientRevision(): string
    {
        $file=__DIR__.'/GmailApi.php';
        $hash=@hash_file('sha256',$file);
        if(!is_string($hash) || $hash==='')throw new RuntimeException('Unable to fingerprint Gmail API client.');
        return hash('sha256','history-catchup-v1|'.$hash);
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public static function operationalTaskMetadata(string $task,array $result): array
    {
        $status=strtoupper(trim((string)($result['status']??'UNKNOWN')));
        $metadata=['status'=>$status!==''?$status:'UNKNOWN'];
        if($task==='gmail'){
            if(array_key_exists('has_more',$result))$metadata['has_more']=($result['has_more']===true);
            foreach(['messages','events'] as $key){
                if(!array_key_exists($key,$result))continue;
                $metadata[$key]=max(0,min(1000,(int)$result[$key]));
            }
            if(array_key_exists('checkpoint_advanced',$result)){
                $metadata['checkpoint_advanced']=($result['checkpoint_advanced']===true);
            }
            if(self::gmailRateLimitRetryDelaySeconds('gmail',$result)!==null){
                $metadata['quota_error']='RATE_LIMITED';
            }
            return $metadata;
        }
        if($task!=='gmail_history_probe')return $metadata;
        foreach(['error_class','error'] as $key){
            $value=$result[$key]??null;
            if(!is_scalar($value))continue;
            $value=trim((string)$value);
            if($value==='')continue;
            $metadata[$key]=strlen($value)>600?mb_strcut($value,0,600,'UTF-8'):$value;
        }
        return $metadata;
    }

    /** @param array<string,mixed> $result */
    public static function gmailRateLimitRetryDelaySeconds(string $task,array $result): ?int
    {
        if(!in_array($task,['gmail','gmail_refund_reconciliation'],true))return null;
        if(strtoupper(trim((string)($result['status']??'')))!=='FAILED')return null;
        $error=strtolower(trim((string)($result['error']??'')));
        if($error==='')return null;
        $rateLimited=str_contains($error,'ratelimitexceeded')
            || preg_match('/gmail api http\s+429\b/',$error)===1;
        return $rateLimited?300:null;
    }

    /** @param array<string,mixed> $result */
    public static function gmailCatchupRetryDelaySeconds(string $task,array $result): ?int
    {
        if($task!=='gmail')return null;
        return ($result['has_more'] ?? false)===true ? 300 : null;
    }

    public static function taskScheduleMarker(
        string $task,DateTimeImmutable $at,?int $retryDelaySeconds=null
    ): string {
        $at=$at->setTimezone(new DateTimeZone('UTC'));
        if($retryDelaySeconds===null)return $at->format(DATE_ATOM);
        $cadence=max(1,(int)(self::cadences()[$task]??43200));
        $age=max(0,$cadence-max(0,$retryDelaySeconds));
        return $at->modify('-'.$age.' seconds')->format(DATE_ATOM);
    }

    /** @param array<string,mixed>|null $cursor */
    public static function gmailCatchupPendingFromCursor(?array $cursor): bool
    {
        if(!is_array($cursor))return false;
        $metadata=is_array($cursor['metadata'] ?? null)?$cursor['metadata']:[];
        return ($metadata['has_more'] ?? false)===true;
    }

    /** @param array<string,mixed> $gmailResult */
    public static function gmailCatchupSkipReason(string $task,array $gmailResult): ?string
    {
        if(($gmailResult['has_more'] ?? false)!==true)return null;
        return in_array($task,['gmail','scheduler','seller_central','review_operations','erp_sales_returns'],true)
            ? 'GMAIL_CATCHUP_INCOMPLETE' : null;
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
        ?string $gmailEvidenceRevision=null,
        ?string $outboxStackRevision=null,
        ?string $gmailClientRevision=null
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
        if(self::$knownActionCases!==[]){
            $lastScheduler=self::timestamp($state['scheduler'] ?? null);
            $nextWake=self::nextKnownWakeAt(self::$knownActionCases,$lastScheduler);
            if($nextWake!==null)$wakeState['next_known_action_at']=$nextWake;
        }
        if(self::knownActionDue($wakeState,$now)){
            $due=[...$due,'known_action_wake','gmail','sp_api','financial','scheduler','seller_central'];
        }
        if(
            is_string($decisionStackRevision)
            && $decisionStackRevision!==''
            && ($state['decision_stack_revision'] ?? null)!==$decisionStackRevision
        ){
            $due=[...$due,'sp_api','financial','scheduler'];
        }
        if(
            is_string($outboxStackRevision)
            && $outboxStackRevision!==''
            && ($state['outbox_stack_revision'] ?? null)!==$outboxStackRevision
        ){
            $due=[...$due,'scheduler','seller_central'];
        }
        if(
            is_string($gmailEvidenceRevision)
            && $gmailEvidenceRevision!==''
            && ($state['gmail_evidence_revision'] ?? null)!==$gmailEvidenceRevision
        ){
            $due[]='gmail_refund_reconciliation';
        }
        if(
            is_string($gmailClientRevision)
            && $gmailClientRevision!==''
            && ($state['gmail_client_revision'] ?? null)!==$gmailClientRevision
        ){
            $due[]='gmail';
            $due[]='gmail_history_probe';
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
            'erp_sales_returns',
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
        self::refreshKnownActionCases($p);
        return [
            'status'=>'OK',
            'tenant_id'=>$context->tenantId(),
            'amazon_connection_id'=>$context->amazonConnectionId(),
            'schema_tables'=>count(SvAmazonReturnsSchema::statements()),
            'policy_seeds'=>$policySeeds,
            'policy_audit'=>$policyAudit,
            'next_known_action_at'=>self::nextKnownWakeAt(self::$knownActionCases),
        ];
    }

    /** @return array<string,mixed> */
    public static function health(
        SvAmazonTenantPersistence $p,
        SvAmazonReturnsConfig $config,
        ?DateTimeImmutable $now=null
    ): array {
        $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
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
            $now,
            $bridgeRequired,
            $p->cursors->load('SELLER_CENTRAL','read_process_heartbeat'),
            $primaryStatusWorker
        );
        $writeFlags=$config->writeFlags();
        $operationalObservations=[];
        foreach(self::cadences() as $task=>$seconds){
            if(in_array($task,['health','policy_monitor'],true))continue;
            $operationalObservations[$task]=$p->cursors->load('OPERATIONAL_TASK',$task);
        }
        $deadLetters=$p->outbox->countDeadLetters();
        $operationalHealth=SvAmazonOperationalHealth::evaluate(self::cadences(),$operationalObservations,$now,$deadLetters);
        $businessHealth=SvAmazonBusinessHealth::evaluate($config->enabled(),$config->mode(),$readiness,$writeFlags,$browserLiveness,$operationalHealth['blockers']);
        $healthStatus=(string)$businessHealth['status'];
        return [
            'status'=>$healthStatus,
            'tenant_id'=>$p->context()->tenantId(),
            'amazon_connection_id'=>$p->context()->amazonConnectionId(),
            'tables'=>$tables,
            'cases'=>$p->cases->countAll(),
            'pending_outbox'=>$p->outbox->countPendingProcessing(),
            'dead_letters'=>$deadLetters,
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
            'write_flags'=>$writeFlags,
            'health_blockers'=>$businessHealth['blockers'],
        ];
    }
}
