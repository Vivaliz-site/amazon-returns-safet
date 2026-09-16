<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/amazon-returns/Runtime.php';
require_once __DIR__ . '/../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__ . '/../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__ . '/../../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__ . '/../../includes/amazon-returns/Projector.php';
require_once __DIR__ . '/../../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__ . '/../../includes/amazon-returns/DecisionCoordinator.php';
require_once __DIR__ . '/../../includes/amazon-returns/SpApi.php';
require_once __DIR__ . '/../../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__ . '/../../includes/amazon-returns/ReturnsReport.php';
require_once __DIR__ . '/../../includes/amazon-returns/GmailApi.php';
require_once __DIR__ . '/../../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__ . '/../../includes/amazon-returns/SafeTEmailReview.php';
require_once __DIR__ . '/../../includes/amazon-returns/ReviewOperations.php';
require_once __DIR__ . '/../../includes/amazon-returns/ErpSalesReturnTask.php';
require_once __DIR__ . '/gmail-ingest.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/reconcile.php';
require_once __DIR__ . '/../../includes/amazon-returns/FinancialRefresh.php';
require_once __DIR__ . '/../../includes/amazon-returns/FinancialRevalidation.php';
require_once __DIR__ . '/../../includes/amazon-returns/FinancialCheckEvidence.php';
require_once __DIR__ . '/../../includes/amazon-returns/RuntimeAudit.php';
require_once __DIR__ . '/../../includes/amazon-returns/LearnedRuleOutcome.php';
require_once __DIR__ . '/seller-central-worker.php';

final class SvAmazonReturnsDaemon
{
    private SvAmazonReturnsConfig $config;
    private SvAmazonTenantPersistence $persistence;
    private string $stateFile;

    public function __construct(
        private PDO $db,
        private SvAmazonTenantContext $context,
        ?SvAmazonReturnsConfig $config=null
    ) {
        $this->config=$config ?? new SvAmazonReturnsConfig();
        $this->persistence=SvAmazonTenantPersistence::create($db,$context);
        $base=$this->config->get(
            'AMAZON_RETURNS_RUNTIME_STATE_FILE',
            sys_get_temp_dir().'/amazon-returns-safet-state.json'
        );
        $this->stateFile=self::scopedStateFile($base,$context);
    }

    public function stateFilePath(): string
    {
        return $this->stateFile;
    }

    /** @return array<string,mixed> */
    public function runOnce(?DateTimeImmutable $now=null): array
    {
        $fixedNow=$now!==null;
        $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $bootstrap=SvAmazonReturnsRuntime::bootstrap($this->db,$this->context);
        $state=$this->loadState();
        $decisionStackRevision=SvAmazonReturnsRuntime::decisionStackRevision();
        $decisionStackChanged=($state['decision_stack_revision'] ?? null)!==$decisionStackRevision;
        $gmailEvidenceRevision=SvAmazonReturnsRuntime::gmailEvidenceRevision();
        $gmailClientRevision=SvAmazonReturnsRuntime::gmailClientRevision();
        $outboxStackRevision=SvAmazonReturnsRuntime::outboxStackRevision();
        $gmailEvidenceChanged=($state['gmail_evidence_revision'] ?? null)!==$gmailEvidenceRevision;
        $gmailClientChanged=($state['gmail_client_revision'] ?? null)!==$gmailClientRevision;
        $due=SvAmazonReturnsRuntime::dueTasks(
            $state,$now,$decisionStackRevision,$gmailEvidenceRevision,$outboxStackRevision,$gmailClientRevision
        );
        $openingRevision=$bootstrap['policy_audit']['policy_key']??null;
        if($openingRevision!==null && ($state['opening_policy_revision']??null)!==$openingRevision){
            $due=array_values(array_unique([...$due,'scheduler','sp_api','financial']));
            $state['opening_policy_revision']=$openingRevision;
        }
        $ruleRevision=$this->persistence->learnedRules->revision();
        if(($state['learned_rule_revision']??null)!==$ruleRevision){
            $due=array_values(array_unique([...$due,'scheduler']));
            $state['learned_rule_revision']=$ruleRevision;
        }
        $writeRevision=$this->config->writeProfileVersion();
        if($writeRevision!==null && ($state['write_profile_revision']??null)!==$writeRevision){
            $due=array_values(array_unique([...$due,'scheduler']));
            $state['write_profile_revision']=$writeRevision;
        }
        $plan=SvAmazonFinancialRefresh::safeSchedule($due,$this->config->enabled(),$this->persistence);
        $due=SvAmazonReturnsRuntime::decisionSafeOrder($plan['due']);
        $results=['bootstrap'=>$bootstrap];
        if(($plan['gate']['status'] ?? '')==='FAILED')$results['financial_refresh_gate']=$plan['gate'];
        foreach($due as $task){
            if($task==='bootstrap')continue;
            $taskNow=$fixedNow ? $now : new DateTimeImmutable('now',new DateTimeZone('UTC'));
            try{
                if($task==='financial' && $this->config->enabled() && !SvAmazonFinancialRefresh::canReconcile(
                    $results['sp_api'] ?? [], SvAmazonFinancialRefresh::initialScanComplete($this->persistence)
                )){
                    $results[$task]=['status'=>'SKIPPED','reason'=>'FINANCIAL_REFRESH_NOT_ACCEPTED'];
                }else{
                    $results[$task]=$this->runTask($task,$taskNow);
                }
            }catch(Throwable $e){
                $results[$task]=[
                    'status'=>'FAILED',
                    'error_class'=>$e::class,
                    'error'=>$this->safeError($e->getMessage()),
                ];
            }
            $taskStatus=strtoupper(trim((string)($results[$task]['status'] ?? 'UNKNOWN')));
            try{
                $this->persistence->cursors->save(
                    'OPERATIONAL_TASK',$task,$taskNow->format(DATE_ATOM),['status'=>$taskStatus]
                );
            }catch(Throwable $observabilityError){
                error_log('[amazon-returns-operational-observability] '.$observabilityError::class);
            }
            $state[$task]=$taskNow->format(DATE_ATOM);
        }
        if(
            $decisionStackChanged
            && isset($results['scheduler'])
            && ($results['scheduler']['status'] ?? null)==='OK'
        ){
            $state['decision_stack_revision']=$decisionStackRevision;
        }
        if(isset($results['seller_central'])
            && SvAmazonReturnsRuntime::sellerCentralCycleAcknowledgesOutboxRevision($results['seller_central'])){
            $state['outbox_stack_revision']=$outboxStackRevision;
        }
        if(
            $gmailEvidenceChanged
            && isset($results['gmail_refund_reconciliation'],$results['scheduler'])
            && ($results['gmail_refund_reconciliation']['status'] ?? null)==='OK'
            && ($results['scheduler']['status'] ?? null)==='OK'
        ){
            $state['gmail_evidence_revision']=$gmailEvidenceRevision;
        }
        if($gmailClientChanged && isset($results['gmail_history_probe'])){
            $state['gmail_client_revision']=$gmailClientRevision;
        }
        try{$results['rule_outcomes']=$this->refreshRuleOutcomes();}
        catch(Throwable $e){$results['rule_outcomes']=['status'=>'FAILED','error_class'=>$e::class];}
        if(SvAmazonReturnsRuntime::financialRefreshContinuationRequired($results)){
            $retryDelay=SvAmazonReturnsRuntime::financialRefreshRetryDelaySeconds($results);
            if($retryDelay===null){
                unset($state['sp_api'],$state['financial']);
            }else{
                $retryNow=$fixedNow ? $now : new DateTimeImmutable('now',new DateTimeZone('UTC'));
                $cadence=max(1,(int)(SvAmazonReturnsRuntime::cadences()['sp_api']??43200));
                $age=max(0,$cadence-$retryDelay);
                $marker=$retryNow->modify('-'.$age.' seconds')->format(DATE_ATOM);
                $state['sp_api']=$marker;
                $state['financial']=$marker;
            }
        }
        $overallStatus=$this->overallStatus($results);
        try{
            $this->persistence->cursors->save(
                'OPERATIONAL','cycle_attempt',$now->format(DATE_ATOM),['status'=>$overallStatus]
            );
            if($overallStatus==='OK'){
                $this->persistence->cursors->save(
                    'OPERATIONAL','cycle_success',$now->format(DATE_ATOM),['status'=>'OK']
                );
            }
        }catch(Throwable $observabilityError){
            error_log('[amazon-returns-operational-cycle] '.$observabilityError::class);
        }
        $this->saveState($state);
        return [
            'status'=>$overallStatus,
            'at'=>$now->format(DATE_ATOM),
            'tenant_id'=>$this->context->tenantId(),
            'amazon_connection_id'=>$this->context->amazonConnectionId(),
            'enabled'=>$this->config->enabled(),
            'mode'=>$this->config->mode(),
            'due'=>$due,
            'results'=>$results,
        ];
    }

    /** @return array<string,mixed> */
    private function refreshRuleOutcomes(): array
    {
        $stats=['status'=>'OK','checked'=>0,'updated'=>0,'counted'=>0,'skipped'=>0,'errors'=>0];
        foreach($this->persistence->ruleApplications->pendingOutcomes(500) as $application){
            $stats['checked']++;
            try{
                $case=$this->persistence->cases->find((int)$application['case_id']);
                if(!is_array($case)){ $stats['skipped']++; continue; }
                $timeline=$this->persistence->events->eventsForCase((int)$case['id']);
                $outcome=SvAmazonLearnedRuleOutcome::classify($case,$timeline);
                $current=(string)($application['outcome']??'PENDING');
                $refs=SvAmazonLearnedRuleOutcome::evidenceRefs($case,$timeline);
                if($outcome!==$current){
                    if($outcome==='PENDING'||$refs===[]){$stats['skipped']++;continue;}
                    $this->persistence->ruleApplications->recordOutcome((int)$application['id'],$outcome,$refs);
                    $stats['updated']++;
                }
                $this->persistence->learnedRules->incrementOutcome((int)$application['rule_id'],$outcome,(string)$application['application_key']);
                $stats['counted']++;
                if($outcome!=='PENDING' && $refs!==[]){
                    $rule=$this->persistence->learnedRules->find((int)$application['rule_id']);
                    if(is_array($rule) && (int)($rule['source_review_id']??0)>0){
                        $this->persistence->reviews->recordOutcome((int)$rule['source_review_id'],$outcome,$refs);
                    }
                }
            }catch(Throwable){$stats['errors']++;}
        }
        if($stats['errors']>0)$stats['status']='PARTIAL';
        return $stats;
    }

    /** @return array<string,mixed> */
    private function runTask(string $task,DateTimeImmutable $now): array
    {
        if($task==='health')return SvAmazonReturnsRuntime::health($this->persistence,$this->config);
        if(!$this->config->enabled()){
            return ['status'=>'SKIPPED_DISABLED','reason'=>'AMAZON_RETURNS_ENABLED=0'];
        }
        return match($task){
            'gmail'=>$this->runGmail(),
            'gmail_history_probe'=>$this->runGmailHistoryProbe(),
            'gmail_refund_reconciliation'=>$this->runGmailRefundReconciliation(),
            'scheduler'=>$this->runScheduler($now),
            'review_operations'=>(new SvAmazonReviewOperations($this->persistence,$this->config))->run($now),
            'seller_central'=>$this->runSellerCentral(),
            'financial'=>$this->runFinancial(),
            'sp_api'=>$this->runSpApiReconciliation(),
            'returns_report'=>$this->runReturnsReport($now),
            'erp_sales_returns'=>$this->runErpSalesReturns(),
            'policy_monitor'=>$this->config->flag('policy_monitor')
                ? ['status'=>'SKIPPED_NO_OBSERVATION_PROVIDER']
                : ['status'=>'SKIPPED_DISABLED','reason'=>'AMAZON_RETURNS_POLICY_MONITOR=0'],
            default=>['status'=>'SKIPPED_UNKNOWN_TASK'],
        };
    }

    /** @return array<string,mixed> */
    private function runErpSalesReturns(): array
    {
        return SvAmazonErpSalesReturnTask::run($this->persistence,$this->config);
    }

    /** @return array<string,mixed> */
    private function dependencyGate(string $dependency,bool $featureEnabled=true): array
    {
        if(!$featureEnabled)return ['status'=>'SKIPPED_DISABLED'];
        $readiness=$this->config->readiness()[$dependency] ?? ['ready'=>false,'missing'=>[]];
        return ($readiness['ready'] ?? false)
            ? ['status'=>'READY_NO_RUNTIME_PROVIDER']
            : ['status'=>'BLOCKED_CREDENTIALS','missing'=>$readiness['missing'] ?? []];
    }

    /** @return array<string,mixed> */
    private function runGmailHistoryProbe(): array
    {
        if(!$this->config->flag('gmail_ingest'))return ['status'=>'SKIPPED_DISABLED'];
        $gate=$this->dependencyGate('gmail');
        if(($gate['status'] ?? '')!=='READY_NO_RUNTIME_PROVIDER')return $gate;
        $gmail=new SvAmazonGmailApiClient($this->config);
        $cursor=SvAmazonGmailIngestor::loadCursor($this->persistence->cursors,'history_id');
        $pulled=$gmail->pull($cursor,1);
        return [
            'status'=>'OK',
            'messages'=>count($pulled['messages'] ?? []),
            'recovered_cursor'=>($pulled['recovered_cursor'] ?? false)===true,
            'cursor_advanced'=>false,
            'external_write'=>false,
        ];
    }

    /** @return array<string,mixed> */
    private function runGmail(): array
    {
        $ingestEnabled=$this->config->flag('gmail_ingest');
        $emailActions=array_values(array_filter(
            ['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY'],
            fn(string $action):bool=>$this->config->externalWriteAllowed($action)
        ));
        $emailWriteEnabled=$emailActions!==[];
        if(!$ingestEnabled && !$emailWriteEnabled)return ['status'=>'SKIPPED_DISABLED'];
        $gate=$this->dependencyGate('gmail');
        if(($gate['status'] ?? '')!=='READY_NO_RUNTIME_PROVIDER')return $gate;

        $gmail=new SvAmazonGmailApiClient($this->config);
        $result=[
            'status'=>'OK','messages'=>0,'events'=>0,'review_claimed'=>0,
            'review_sent'=>0,'reply_sent'=>0,'review_failed'=>0,
        ];
        if($ingestEnabled){
            $ingestor=new SvAmazonGmailIngestor();
            $cursor=SvAmazonGmailIngestor::loadCursor($this->persistence->cursors,'history_id');
            $pulled=$gmail->pull($cursor);
            $ingested=$ingestor->ingest(
                $pulled['messages'],
                fn(array $event):int=>SvAmazonGmailEventSink::persist($this->persistence,$event),
                (string)$pulled['cursor']
            );
            SvAmazonGmailIngestor::saveCursor(
                $this->persistence->cursors,
                'history_id',
                (string)$pulled['cursor'],
                [
                    'message_count'=>$ingested['messages'],
                    'event_count'=>$ingested['events'],
                    'recovered_cursor'=>$pulled['recovered_cursor'] ?? false,
                ]
            );
            $result['messages']=$ingested['messages'];
            $result['events']=$ingested['events'];
            $result['recovered_cursor']=$pulled['recovered_cursor'] ?? false;
        }

        if(!$emailWriteEnabled)return $result;
        $rows=$this->persistence->outbox->claimBatch(
            10,
            $emailActions
        );
        $result['review_claimed']=count($rows);
        foreach($rows as $row){
            try{
                $caseId=(int)($row['case_id'] ?? 0);
                $case=$this->persistence->cases->find($caseId);
                if(!is_array($case))throw new RuntimeException('SAFE-T email case not found.');
                $timeline=$this->persistence->events->eventsForCase($caseId);
                $kind=strtoupper((string)($row['kind'] ?? ''));
                $payload=is_array($row['payload'] ?? null)?$row['payload']:[];
                $snapshot=is_array($payload['write_snapshot'] ?? null)?$payload['write_snapshot']:[];
                $snapshotV2=(int)($snapshot['format_version'] ?? 0)===2;
                $storedMessage=is_array($snapshot['message'] ?? null)?$snapshot['message']:null;
                $writeContentSha256=is_string($snapshot['content_sha256'] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/i',(string)$snapshot['content_sha256'])===1
                    ? strtolower((string)$snapshot['content_sha256']) : null;
                if($snapshotV2 && ($storedMessage===null || $writeContentSha256===null)){
                    throw new LogicException('WRITE_SNAPSHOT_MISSING');
                }
                if($kind==='SAFE_T_EMAIL_REPLY'){
                    $message=$snapshotV2?$storedMessage:SvAmazonSafeTEmailReview::composeReply($case,$timeline,null,SvAmazonRequestedWait::jobResumeScope($row));
                    $sent=$gmail->sendReplyOnce(
                        (string)$message['to'],(string)$message['subject'],(string)$message['body'],(string)$message['thread_id'],
                        (string)$message['in_reply_to'],(string)$row['idempotency_key']
                    );
                    $eventType='SAFE_T_EMAIL_REPLY_SENT';
                    $result['reply_sent']++;
                }else{
                    $message=$snapshotV2?$storedMessage:SvAmazonSafeTEmailReview::compose($case,$timeline);
                    $sent=$gmail->sendOnce(
                        (string)$message['to'],(string)$message['subject'],(string)$message['body'],
                        (string)$row['idempotency_key']
                    );
                    $eventType='SAFE_T_EMAIL_REVIEW_SENT';
                    $result['review_sent']++;
                }
                $this->persistence->events->append([
                    'case_id'=>$caseId,
                    'event_type'=>$eventType,
                    'source'=>'GMAIL',
                    'source_event_id'=>(string)$sent['message_id'],
                    'idempotency_key'=>hash(
                        'sha256',strtolower($eventType).'|'.(string)$row['idempotency_key']
                    ),
                    'occurred_at'=>gmdate('Y-m-d H:i:s'),
                    'payload'=>[
                        'resume_scope'=>SvAmazonRequestedWait::jobResumeScope($row),
                        'order_id'=>(string)$case['amazon_order_id'],
                        'safe_t_id'=>$case['safe_t_id'] ?? null,
                        'gmail_message_id'=>$sent['message_id'],
                        'gmail_thread_id'=>$sent['thread_id'],
                        'outbox_id'=>(int)$row['id'],
                        'write_content_sha256'=>$writeContentSha256,
                    ],
                    'evidence_sha256'=>null,
                ]);
                $this->persistence->cases->update($caseId,[
                    'state'=>SvAmazonReturnStates::EMAIL_REVIEW_SENT,
                ]);
                $this->persistence->outbox->markSucceeded((int)$row['id']);
            }catch(Throwable $e){
                $this->persistence->outbox->markFailed($row,$e);
                $result['review_failed']++;
            }
        }
        if($result['review_failed']>0)$result['status']='PARTIAL';
        return $result;
    }

    /** @return array<string,mixed> */
    private function runGmailRefundReconciliation(): array
    {
        if(!$this->config->flag('gmail_ingest'))return ['status'=>'SKIPPED_DISABLED'];
        $gate=$this->dependencyGate('gmail');
        if(($gate['status'] ?? '')!=='READY_NO_RUNTIME_PROVIDER')return $gate;
        $gmail=new SvAmazonGmailApiClient($this->config);
        $messages=$gmail->searchMessages('newer_than:90d reembolso iniciado',500);
        $ingestor=new SvAmazonGmailIngestor();
        $ingested=$ingestor->ingest(
            $messages,
            fn(array $event):int=>SvAmazonGmailEventSink::persist($this->persistence,$event),
            'daily-refund-search'
        );
        return [
            'status'=>'OK',
            'query'=>'newer_than:90d reembolso iniciado',
            'messages'=>$ingested['messages'],
            'events'=>$ingested['events'],
            'financial_truth'=>false,
        ];
    }
    /** @return array<string,mixed> */
    private function runScheduler(DateTimeImmutable $now): array
    {
        $cases=$this->persistence->cases->openCases(500);
        $knownCaseIds=[];
        foreach($cases as $caseRow){
            $knownId=(int)($caseRow['id']??0);
            if($knownId>0)$knownCaseIds[$knownId]=true;
        }
        foreach($this->persistence->outbox->pendingWriteCaseIds() as $pendingCaseId){
            if(isset($knownCaseIds[$pendingCaseId]))continue;
            $pendingCase=$this->persistence->cases->find($pendingCaseId);
            if(!is_array($pendingCase))continue;
            $cases[]=$pendingCase;
            $knownCaseIds[$pendingCaseId]=true;
        }
        $policies=$this->persistence->policies->allActive();
        $engine=new SvAmazonSafeTDecisionEngine();
        $coordinator=new SvAmazonDecisionCoordinator($engine,$this->persistence,$this->config);
        $decisions=0;
        $enqueued=0;
        $blockedWrites=0;
        $supersededWrites=0;
        $financialChecks=0;
        $decisionAudit=[];
        $requestedFinancialRecheck=false;
        foreach($cases as $case){
            $caseId=(int)($case['id'] ?? 0);
            if($caseId<1)continue;
            $projected=SvAmazonReturnProjector::project(
                $this->persistence->cases,
                $this->persistence->events,
                $caseId
            );
            $projected['policies']=$policies;
            $policy=SvAmazonReturnPolicyEngine::evaluate($projected,$now);
            $timeline=$this->persistence->events->eventsForCase($caseId);
            $decision=$coordinator->nextAction($projected,$timeline,$policy,$now);
            $decision=SvAmazonReturnsScheduler::normalizeRecoveryChannel($projected,$decision,$now);
            $action=(string)($decision['action'] ?? 'WAIT');
            $isWrite=SvAmazonReturnsScheduler::isWriteAction($decision);
            $keepKind=null;
            $keepKey=null;
            if($isWrite){
                $keepKind=$action;
                $keepKey=trim((string)($decision['idempotency_key']??''));
                if($keepKey==='')throw new LogicException('Current write decision missing idempotency key during stale-write sweep.');
            }
            $supersedeReason='SUPERSEDED_BY_CURRENT_DECISION:'
                .$action.':'
                .(string)($decision['reason']??'UNSPECIFIED');
            $supersededWrites+=$this->persistence->outbox->supersedePendingWritesExcept(
                $caseId,$keepKind,$keepKey,$supersedeReason
            );
            $timing=['eligibility_at'=>$policy['eligibility_at']??null,'policy_version_id'=>$policy['policy_version_id']??null];
            if(array_key_exists('next_action_at',$decision)){
                $candidate=SvAmazonRequestedWait::timestamp($decision['next_action_at']);
                if($candidate!==null && $candidate>$now)$timing['next_action_at']=$candidate->format('Y-m-d H:i:s');
                elseif($action==='CHECK_FINANCES')$timing['next_action_at']=$decision['next_action_at'];
                elseif(!$isWrite)$timing['next_action_at']=null;
            }elseif(trim((string)($projected['safe_t_id']??''))==='' && ($policy['eligible']??false)!==true){
                $timing['next_action_at']=$policy['eligibility_at']??null;
            }elseif(!$isWrite){
                $timing['next_action_at']=null;
            }
            $this->persistence->cases->update($caseId,$timing);
            $projected=array_replace($projected,$timing);
            $decisions++;
            if($action==='CHECK_FINANCES')$financialChecks++;
            if($action==='CHECK_FINANCES')$requestedFinancialRecheck=true;
            $decisionAudit[]=SvAmazonReturnsRuntimeAudit::decision($projected,$timeline,$policy,$decision);
            if($action==='CLOSE_LOSS'){
                $this->persistence->cases->update($caseId,[
                    'state'=>SvAmazonReturnStates::CLOSED_LOSS,
                    'terminal_reason'=>'EMAIL_REVIEW_FINAL_DENIAL',
                    'closed_at'=>$projected['closed_at'] ?? gmdate('Y-m-d H:i:s'),
                ]);
                continue;
            }
            if(SvAmazonReturnsScheduler::isReadAction($decision)){
                $scheduled=(new SvAmazonReturnsScheduler($engine))->scheduleDecision(
                    $this->persistence->outbox,$projected,$decision,$timeline
                );
                if(($scheduled['enqueued'] ?? false))$enqueued++;
                continue;
            }
            if(!SvAmazonReturnsScheduler::isWriteAction($decision))continue;
            if(!$this->config->externalWriteAllowed($action)){
                $blockedWrites++;
                continue;
            }
            if(!$this->config->writeCaseAllowed($caseId)){
                $blockedWrites++;
                continue;
            }
            $dependency=SvAmazonReturnsScheduler::dependencyForAction($action);
            if(!(($this->config->readiness()[$dependency]['ready'] ?? false))){
                $blockedWrites++;
                continue;
            }
            $scheduled=(new SvAmazonReturnsScheduler($engine))->scheduleDecision(
                $this->persistence->outbox,$projected,$decision,$timeline
            );
            if(($scheduled['enqueued'] ?? false))$enqueued++;
            if(($scheduled['outbox_id'] ?? null)!==null){
                $this->persistence->cases->update($caseId,['next_action_at'=>null]);
            }
        }
        return [
            'status'=>'OK','cases'=>count($cases),'decisions'=>$decisions,
            'enqueued'=>$enqueued,'blocked_writes'=>$blockedWrites,'superseded_writes'=>$supersededWrites,
            'financial_checks_requested'=>$financialChecks,
            'decision_audit'=>$decisionAudit,
            'financial_recheck_requested'=>$requestedFinancialRecheck,
        ];
    }

    /** @return array<string,mixed> */
    private function runSpApiReconciliation(): array
    {
        $gate=$this->dependencyGate('sp_api');
        if(($gate['status'] ?? '')!=='READY_NO_RUNTIME_PROVIDER')return $gate;
        $api=new SvAmazonReturnsSpApi();
        $batch=SvAmazonFinancialRefresh::nextBatch($this->persistence,25);
        $orders=$batch['order_ids'];
        $synced=0;
        $persistedCases=0;
        $failures=0;
        $safeTReads=0;
        $safeTEvents=0;
        $safeTEmpty=0;
        $safeTFailures=0;
        $throttleMs=max(
            0,min(10000,(int)$this->config->get('AMAZON_RETURNS_SP_API_THROTTLE_MS','2100'))
        );
        foreach($orders as $index=>$orderId){
            $financeComplete=true;
            try{
                $order=$api->syncOrder($orderId);
                $financial=$api->listTransactions($orderId);
                $saved=SvAmazonSpApiEventSink::persist(
                    $this->persistence,$order,$financial['transactions'] ?? []
                );
                $persistedCases+=count($saved['cases'] ?? []);
                $hasSafeT=false;
                foreach($this->persistence->cases->forOrder($orderId) as $case){
                    if(trim((string)($case['safe_t_id']??''))!==''){
                        $hasSafeT=true;
                        break;
                    }
                }
                if($hasSafeT){
                    try{
                        $safeT=$api->listSafeTReimbursements($orderId);
                        $safeTReads++;
                        $safeTList=is_array($safeT['events']??null)?$safeT['events']:[];
                        if($safeTList===[])$safeTEmpty++;
                        $persisted=SvAmazonSpApiEventSink::persistSafeTReimbursements(
                            $this->persistence,
                            $orderId,
                            $safeTList,
                            is_array($safeT['request_ids']??null)?$safeT['request_ids']:[],
                            is_array($safeT['response_sha256']??null)?$safeT['response_sha256']:[]
                        );
                        $safeTEvents+=(int)($persisted['persisted']??0);
                    }catch(Throwable){
                        $safeTFailures++;
                        $financeComplete=false;
                    }
                }
                $this->recordFinanceSource($orderId,$financeComplete);
                $synced++;
            }catch(Throwable){
                $failures++;
                try{$this->recordFinanceSource($orderId,false);}catch(Throwable){}
            }
            if($throttleMs>0 && $index<count($orders)-1)usleep($throttleMs*1000);
        }
        $scan=SvAmazonFinancialRefresh::recordAttempted($this->persistence,$batch,$failures+$safeTFailures);
        return [
            'status'=>($failures>0||$safeTFailures>0)?'PARTIAL':'OK','orders'=>count($orders),
            'initial_scan_complete'=>$scan['initial_scan_complete'],
            'cycle_attempted'=>$scan['cycle_attempted'],
            'cycle_failures'=>$scan['cycle_failures'],
            'rotation_wrapped'=>$batch['wrapped'],'rotation_has_more'=>$batch['has_more'],
            'synced'=>$synced,'persisted_cases'=>$persistedCases,'failures'=>$failures,
            'safe_t_reads'=>$safeTReads,'safe_t_events'=>$safeTEvents,
            'safe_t_empty'=>$safeTEmpty,'safe_t_failures'=>$safeTFailures,
        ];
    }

    /** @return array<string,mixed> */
    private function recordFinanceSource(string $orderId,bool $complete): void
    {
        $cases=$this->persistence->cases->forOrder($orderId);
        $unambiguous=count($cases)===1;
        $at=gmdate('Y-m-d H:i:s');
        foreach($cases as $case){
            $caseId=(int)$case['id'];
            $this->persistence->events->append(SvAmazonFinancialRevalidation::sourceEvent($caseId,$complete && $unambiguous,$at));
            if($complete && $unambiguous)$this->persistence->events->append(SvAmazonFinancialCheckEvidence::refresh($caseId,$orderId,new DateTimeImmutable($at,new DateTimeZone('UTC'))));
        }
    }

    private function runFinancial(): array
    {
        $worker=new SvAmazonReturnsReconcileWorker();
        $batch=SvAmazonFinancialRefresh::nextReconciliationBatch($this->persistence,250);
        $cases=$batch['cases'];
        $updated=0;
        $failed=0;
        $withTransactions=0;
        $financialAudit=[];
        $caseCount=0;
        foreach($cases as $case){
            $caseCount++;
            $caseId=(int)($case['id'] ?? 0);
            if($caseId<1)continue;
            try{
            $events=$this->persistence->events->eventsForCase($caseId);
            $transactions=$worker->transactionsFromEvents($events);
            $result=$worker->reconcileCase($case,$transactions);
            $apply=$worker->shouldUpdateCase($case,$transactions);
            $financialAudit[]=SvAmazonReturnsRuntimeAudit::financial($case,$transactions,$result,$apply);
            $confirmation=SvAmazonFinancialRevalidation::confirmation($case,$events,$result,gmdate('Y-m-d H:i:s'));
            $check=SvAmazonFinancialCheckEvidence::reconciled($caseId,$events,$result,new DateTimeImmutable('now',new DateTimeZone('UTC')));
            if($check!==null)$this->persistence->events->append($check);
            if(!$apply){
                if($confirmation!==null)$this->persistence->events->append($confirmation);
                continue;
            }
            if($transactions!==[])$withTransactions++;
            $terminal=$result['state']===SvAmazonReturnStates::RECOVERED;
            $this->persistence->cases->update($caseId,[
                'reconciled_credit_amount'=>$result['credit_amount'],
                'state'=>$result['state'],
                'terminal_reason'=>$terminal?'FINANCIAL_RECOVERED':null,
                'closed_at'=>$terminal
                    ? ($case['closed_at'] ?? gmdate('Y-m-d H:i:s'))
                    : null,
                'next_action_at'=>$terminal?null:($case['next_action_at']??null),
            ]);
            if($confirmation!==null)$this->persistence->events->append($confirmation);
            $updated++;
            }catch(Throwable $e){
                $failed++;
                $financialAudit[]=['case_id'=>$caseId,'status'=>'FAILED','applied'=>false,'error_class'=>$e::class];
            }
        }
        SvAmazonFinancialRefresh::recordReconciliationBatch($this->persistence,$batch);
        return [
            'status'=>$failed>0?'PARTIAL':'OK','failed'=>$failed,'cases'=>$caseCount,
            'with_transactions'=>$withTransactions,'updated'=>$updated,
            'rotation_has_more'=>$batch['has_more'],'rotation_wrapped'=>$batch['wrapped'],
            'financial_audit'=>$financialAudit,
        ];
    }

    /** @return array<string,mixed> */
    private function runReturnsReport(DateTimeImmutable $now): array
    {
        $gate=$this->dependencyGate('sp_api');
        if(($gate['status'] ?? '')!=='READY_NO_RUNTIME_PROVIDER')return $gate;
        $api=new SvAmazonReturnsSpApi();
        $pending=SvAmazonReturnsReport::loadCursor($this->persistence,'pending_report');
        $requested=false;
        if($pending===null){
            $highWater=SvAmazonReturnsReport::loadCursor(
                $this->persistence,'return_date_high_water'
            );
            $window=SvAmazonReturnsReport::nextWindow(
                $highWater['value'] ?? null,
                SvAmazonReturnsReport::earliestCaseDate($this->persistence),
                $now
            );
            $report=$api->requestReturnsReport($window['from'],$window['to']);
            $metadata=[
                'from'=>$window['from']->format(DATE_ATOM),
                'to'=>$window['to']->format(DATE_ATOM),
                'request_id'=>$report['request_id'] ?? null,
            ];
            SvAmazonReturnsReport::saveCursor(
                $this->persistence,'pending_report',(string)$report['report_id'],$metadata
            );
            $pending=['value'=>(string)$report['report_id'],'metadata'=>$metadata];
            $requested=true;
        }

        $reportId=trim((string)$pending['value']);
        $metadata=is_array($pending['metadata'] ?? null)?$pending['metadata']:[];
        $pollAttempts=max(
            1,min(12,(int)$this->config->get('AMAZON_RETURNS_REPORT_POLL_ATTEMPTS','6'))
        );
        $pollMs=max(
            250,min(10000,(int)$this->config->get('AMAZON_RETURNS_REPORT_POLL_MS','1500'))
        );
        $status=null;
        for($attempt=0;$attempt<$pollAttempts;$attempt++){
            $status=$api->getReport($reportId);
            if(in_array($status['processing_status'],['DONE','CANCELLED','FATAL'],true))break;
            if($attempt+1<$pollAttempts)usleep($pollMs*1000);
        }
        if(!is_array($status))throw new RuntimeException('Amazon report status unavailable.');
        $processing=(string)$status['processing_status'];
        if(in_array($processing,['IN_QUEUE','IN_PROGRESS'],true)){
            return [
                'status'=>'PENDING','report_id'=>$reportId,
                'processing_status'=>$processing,'requested'=>$requested,
            ];
        }
        if($processing==='FATAL'){
            SvAmazonReturnsReport::clearCursor($this->persistence,'pending_report');
            return [
                'status'=>'PARTIAL','report_id'=>$reportId,'processing_status'=>'FATAL',
                'reason'=>'REPORT_FATAL_RETRY_WINDOW',
            ];
        }

        $windowEnd=trim((string)(
            $metadata['to'] ?? $status['data_end_time'] ?? $now->format(DATE_ATOM)
        ));
        if($processing==='CANCELLED'){
            SvAmazonReturnsReport::saveCursor(
                $this->persistence,'return_date_high_water',$windowEnd,
                ['rows'=>0,'processing_status'=>'CANCELLED']
            );
            SvAmazonReturnsReport::clearCursor($this->persistence,'pending_report');
            return [
                'status'=>'OK','report_id'=>$reportId,
                'processing_status'=>'CANCELLED','rows'=>0,
            ];
        }

        $documentId=trim((string)($status['report_document_id'] ?? ''));
        if($documentId===''){
            throw new RuntimeException('Completed Amazon report is missing reportDocumentId.');
        }
        $document=$api->downloadReportDocument($documentId);
        $rows=SvAmazonReturnsReport::parse((string)$document['content']);
        $persisted=SvAmazonReturnsReport::persistRows(
            $this->persistence,$rows,$documentId,(string)$document['content_sha256']
        );
        SvAmazonReturnsReport::saveCursor(
            $this->persistence,'return_date_high_water',$windowEnd,
            [
                'report_id'=>$reportId,
                'document_id'=>$documentId,
                'document_sha256'=>$document['content_sha256'],
                'rows'=>count($rows),
            ]
        );
        SvAmazonReturnsReport::clearCursor($this->persistence,'pending_report');
        return [
            'status'=>'OK','report_id'=>$reportId,'document_id'=>$documentId,
            'processing_status'=>'DONE',
        ]+$persisted;
    }

    /** @return array<string,mixed> */
    private function runSellerCentral(): array
    {
        if($this->config->sellerCentralBridgeMode()==='polling'){
            return ['status'=>'REMOTE_POLLING','reason'=>'SELLER_CENTRAL_BRIDGE_OWNS_OUTBOX'];
        }
        $bridge=$this->config->readiness()['seller_central_bridge']
            ?? ['ready'=>false,'missing'=>[]];
        if(!($bridge['ready'] ?? false)){
            return ['status'=>'BLOCKED_CREDENTIALS','missing'=>$bridge['missing'] ?? []];
        }
        $writeFlags=$this->config->writeFlags();
        if(!in_array(true,$writeFlags,true)){
            return [
                'status'=>'SKIPPED_DISABLED',
                'reason'=>'ALL_SELLER_CENTRAL_WRITE_FLAGS_OFF',
            ];
        }

        $rows=$this->persistence->outbox->claimBatch(
            10,['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE']
        );
        $worker=new SvAmazonSellerCentralWorker();
        $processed=0;
        $failed=0;
        foreach($rows as $row){
            $kind=(string)($row['kind'] ?? '');
            if(!($writeFlags[$kind] ?? false)){
                $this->persistence->outbox->releaseUnprocessed((int)$row['id']);
                continue;
            }
            $payload=is_array($row['payload'] ?? null)?$row['payload']:[];
            $payload['kind']=$kind;
            $payload['dry_run']=false;
            $payload['write_flags']=$writeFlags;
            try{
                $actionResult=$worker->execute(['kind'=>$kind,'payload'=>$payload]);
                if(in_array((string)($actionResult['status'] ?? ''),[
                    'ACCEPTED','ALREADY_EXISTS',
                ],true)){
                    $this->persistence->outbox->markSucceeded((int)$row['id']);
                    $processed++;
                }else{
                    $this->persistence->outbox->markFailed(
                        $row,(string)($actionResult['status'] ?? 'FAILED')
                    );
                    $failed++;
                }
            }catch(Throwable $e){
                $this->persistence->outbox->markFailed($row,$e);
                $failed++;
            }
        }
        return [
            'status'=>$failed>0?'PARTIAL':'OK','claimed'=>count($rows),
            'processed'=>$processed,'failed'=>$failed,
        ];
    }

    /** @return array<string,string> */
    private function loadState(): array
    {
        if(!is_file($this->stateFile))return [];
        $raw=@file_get_contents($this->stateFile);
        if(!is_string($raw) || trim($raw)==='')return [];
        $decoded=json_decode($raw,true);
        if(!is_array($decoded))return [];
        $state=[];
        foreach($decoded as $key=>$value){
            if(is_string($key) && is_string($value))$state[$key]=$value;
        }
        return $state;
    }

    /** @param array<string,string> $state */
    private function saveState(array $state): void
    {
        $dir=dirname($this->stateFile);
        if(!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)){
            throw new RuntimeException('Unable to create Amazon returns runtime state directory.');
        }
        $tmp=$this->stateFile.'.tmp.'.getmypid();
        $json=json_encode(
            $state,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES
        );
        if(@file_put_contents($tmp,$json.PHP_EOL,LOCK_EX)===false){
            throw new RuntimeException('Unable to write Amazon returns runtime state.');
        }
        if(!@rename($tmp,$this->stateFile)){
            @unlink($tmp);
            throw new RuntimeException('Unable to publish Amazon returns runtime state.');
        }
    }

    private static function scopedStateFile(
        string $base,
        SvAmazonTenantContext $context
    ): string {
        $base=trim($base);
        if($base==='')throw new RuntimeException('Amazon returns runtime state path is empty.');
        $scope='.tenant-'.$context->tenantId().'-connection-'.$context->amazonConnectionId();
        if(str_contains(basename($base),$scope))return $base;
        $extension=pathinfo($base,PATHINFO_EXTENSION);
        if($extension==='')return $base.$scope;
        return substr($base,0,-strlen($extension)-1).$scope.'.'.$extension;
    }

    private function safeError(string $message): string
    {
        $message=preg_replace(
            '/(access_token|refresh_token|client_secret|authorization)\s*[=:]\s*[^\s,;]+/i',
            '$1=[redacted]',$message
        ) ?? $message;
        return function_exists('mb_substr')
            ? mb_substr($message,0,600,'UTF-8')
            : substr($message,0,600);
    }

    /** @param array<string,mixed> $results */
    private function overallStatus(array $results): string
    {
        $statuses=[];
        foreach($results as $result){
            if(is_array($result) && isset($result['status'])){
                $statuses[]=(string)$result['status'];
            }
        }
        if(in_array('FAILED',$statuses,true))return 'FAILED';
        if(in_array('PARTIAL',$statuses,true))return 'PARTIAL';
        if(in_array('DEGRADED',$statuses,true))return 'DEGRADED';
        if(in_array('BLOCKED_CREDENTIALS',$statuses,true))return 'DEGRADED';
        return 'OK';
    }
}

function sv_amazon_returns_daemon_main(array $argv): int
{
    $once=in_array('--once',$argv,true);
    $sleepSeconds=30;
    foreach($argv as $arg){
        if(str_starts_with((string)$arg,'--sleep=')){
            $sleepSeconds=max(5,min(300,(int)substr((string)$arg,8)));
        }
    }
    $db=amazon_returns_pdo();
    if(!$db instanceof PDO){
        fwrite(STDERR,"Amazon returns daemon: database unavailable\n");
        return 2;
    }
    try{
        $config=new SvAmazonReturnsConfig();
        SvAmazonReturnsSchema::ensure($db);
        $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
        $daemon=new SvAmazonReturnsDaemon($db,$context,$config);
    }catch(Throwable $e){
        fwrite(STDERR,"Amazon returns daemon: tenant context unavailable\n");
        return 3;
    }
    do{
        $result=$daemon->runOnce();
        fwrite(STDOUT,json_encode(
            $result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        ).PHP_EOL);
        if($once)break;
        sleep($sleepSeconds);
    }while(true);
    return 0;
}

if(realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__){
    exit(sv_amazon_returns_daemon_main($argv ?? []));
}
