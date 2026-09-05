<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/Database.php';
require_once __DIR__ . '/../../includes/amazon-returns/Runtime.php';
require_once __DIR__ . '/../../includes/amazon-returns/TenantRegistry.php';
require_once __DIR__ . '/../../includes/amazon-returns/TenantPersistence.php';
require_once __DIR__ . '/../../includes/amazon-returns/PolicyEngine.php';
require_once __DIR__ . '/../../includes/amazon-returns/Projector.php';
require_once __DIR__ . '/../../includes/amazon-returns/SafeTDecisionEngine.php';
require_once __DIR__ . '/../../includes/amazon-returns/SpApi.php';
require_once __DIR__ . '/../../includes/amazon-returns/SpApiEventSink.php';
require_once __DIR__ . '/../../includes/amazon-returns/ReturnsReport.php';
require_once __DIR__ . '/../../includes/amazon-returns/GmailApi.php';
require_once __DIR__ . '/../../includes/amazon-returns/GmailEventSink.php';
require_once __DIR__ . '/../../includes/amazon-returns/SafeTEmailReview.php';
require_once __DIR__ . '/gmail-ingest.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/reconcile.php';
require_once __DIR__ . '/../../includes/amazon-returns/FinancialRefresh.php';
require_once __DIR__ . '/../../includes/amazon-returns/RuntimeAudit.php';
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
        $now ??= new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $bootstrap=SvAmazonReturnsRuntime::bootstrap($this->db,$this->context);
        $state=$this->loadState();
        $due=SvAmazonReturnsRuntime::dueTasks($state,$now);
        $plan=SvAmazonFinancialRefresh::safeSchedule($due,$this->config->enabled(),$this->persistence);
        $due=$plan['due'];
        $results=['bootstrap'=>$bootstrap];
        if(($plan['gate']['status'] ?? '')==='FAILED')$results['financial_refresh_gate']=$plan['gate'];
        foreach($due as $task){
            if($task==='bootstrap')continue;
            try{
                if($task==='financial' && $this->config->enabled() && !SvAmazonFinancialRefresh::canReconcile(
                    $results['sp_api'] ?? [], SvAmazonFinancialRefresh::initialScanComplete($this->persistence)
                )){
                    $results[$task]=['status'=>'SKIPPED','reason'=>'FINANCIAL_REFRESH_NOT_ACCEPTED'];
                }else{
                    $results[$task]=$this->runTask($task,$now);
                }
            }catch(Throwable $e){
                $results[$task]=[
                    'status'=>'FAILED',
                    'error_class'=>$e::class,
                    'error'=>$this->safeError($e->getMessage()),
                ];
            }
            $state[$task]=$now->format(DATE_ATOM);
        }
        $this->saveState($state);
        return [
            'status'=>$this->overallStatus($results),
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
    private function runTask(string $task,DateTimeImmutable $now): array
    {
        if($task==='health')return SvAmazonReturnsRuntime::health($this->persistence,$this->config);
        if(!$this->config->enabled()){
            return ['status'=>'SKIPPED_DISABLED','reason'=>'AMAZON_RETURNS_ENABLED=0'];
        }
        return match($task){
            'gmail'=>$this->runGmail(),
            'scheduler'=>$this->runScheduler($now),
            'seller_central'=>$this->runSellerCentral(),
            'financial'=>$this->runFinancial(),
            'sp_api'=>$this->runSpApiReconciliation(),
            'returns_report'=>$this->runReturnsReport($now),
            'policy_monitor'=>$this->config->flag('policy_monitor')
                ? ['status'=>'SKIPPED_NO_OBSERVATION_PROVIDER']
                : ['status'=>'SKIPPED_DISABLED','reason'=>'AMAZON_RETURNS_POLICY_MONITOR=0'],
            default=>['status'=>'SKIPPED_UNKNOWN_TASK'],
        };
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
    private function runGmail(): array
    {
        $ingestEnabled=$this->config->flag('gmail_ingest');
        $emailWriteEnabled=$this->config->externalWriteAllowed('SAFE_T_EMAIL_REVIEW');
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
            ['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY']
        );
        $result['review_claimed']=count($rows);
        foreach($rows as $row){
            try{
                $caseId=(int)($row['case_id'] ?? 0);
                $case=$this->persistence->cases->find($caseId);
                if(!is_array($case))throw new RuntimeException('SAFE-T email case not found.');
                $timeline=$this->persistence->events->eventsForCase($caseId);
                $kind=strtoupper((string)($row['kind'] ?? ''));
                if($kind==='SAFE_T_EMAIL_REPLY'){
                    $message=SvAmazonSafeTEmailReview::composeReply($case,$timeline);
                    $sent=$gmail->sendReplyOnce(
                        $message['to'],$message['subject'],$message['body'],$message['thread_id'],
                        $message['in_reply_to'],(string)$row['idempotency_key']
                    );
                    $eventType='SAFE_T_EMAIL_REPLY_SENT';
                    $result['reply_sent']++;
                }else{
                    $message=SvAmazonSafeTEmailReview::compose($case,$timeline);
                    $sent=$gmail->sendOnce(
                        $message['to'],$message['subject'],$message['body'],
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
                        'order_id'=>(string)$case['amazon_order_id'],
                        'safe_t_id'=>$case['safe_t_id'] ?? null,
                        'gmail_message_id'=>$sent['message_id'],
                        'gmail_thread_id'=>$sent['thread_id'],
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
    private function runScheduler(DateTimeImmutable $now): array
    {
        $cases=$this->persistence->cases->openCases(500);
        $policies=$this->persistence->policies->allActive();
        $engine=new SvAmazonSafeTDecisionEngine();
        $decisions=0;
        $enqueued=0;
        $blockedWrites=0;
        $decisionAudit=[];
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
            $decision=$engine->nextAction($projected,$timeline,$policy);
            $decisions++;
            $action=(string)($decision['action'] ?? 'WAIT');
            $decisionAudit[]=SvAmazonReturnsRuntimeAudit::decision($projected,$timeline,$policy,$decision);
            if($action==='CLOSE_LOSS'){
                $this->persistence->cases->update($caseId,[
                    'state'=>SvAmazonReturnStates::CLOSED_LOSS,
                    'terminal_reason'=>'EMAIL_REVIEW_FINAL_DENIAL',
                    'closed_at'=>$projected['closed_at'] ?? gmdate('Y-m-d H:i:s'),
                ]);
                continue;
            }
            if(!SvAmazonReturnsScheduler::isWriteAction($decision))continue;
            if(!$this->config->externalWriteAllowed($action)){
                $blockedWrites++;
                continue;
            }
            $dependency=SvAmazonReturnsScheduler::dependencyForAction($action);
            if(!(($this->config->readiness()[$dependency]['ready'] ?? false))){
                $blockedWrites++;
                continue;
            }
            $scheduled=(new SvAmazonReturnsScheduler($engine))->schedule(
                $this->persistence->outbox,$projected,$timeline,$policy
            );
            if(($scheduled['outbox_id'] ?? null)!==null)$enqueued++;
        }
        return [
            'status'=>'OK','cases'=>count($cases),'decisions'=>$decisions,
            'enqueued'=>$enqueued,'blocked_writes'=>$blockedWrites,
            'decision_audit'=>$decisionAudit,
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
                    }
                }
                $synced++;
            }catch(Throwable){
                $failures++;
            }
            if($throttleMs>0 && $index<count($orders)-1)usleep($throttleMs*1000);
        }
        $scan=SvAmazonFinancialRefresh::recordAttempted($this->persistence,$batch,$failures+$safeTFailures);
        return [
            'status'=>($failures>0||$safeTFailures>0)?'PARTIAL':'OK','orders'=>count($orders),
            'initial_scan_complete'=>$scan['initial_scan_complete'],
            'cycle_attempted'=>$scan['cycle_attempted'],
            'cycle_failures'=>$scan['cycle_failures'],
            'rotation_wrapped'=>$batch['wrapped'],
            'synced'=>$synced,'persisted_cases'=>$persistedCases,'failures'=>$failures,
            'safe_t_reads'=>$safeTReads,'safe_t_events'=>$safeTEvents,
            'safe_t_empty'=>$safeTEmpty,'safe_t_failures'=>$safeTFailures,
        ];
    }

    /** @return array<string,mixed> */
    private function runFinancial(): array
    {
        $worker=new SvAmazonReturnsReconcileWorker();
        $updated=0;
        $withTransactions=0;
        $financialAudit=[];
        $caseCount=0;
        foreach(SvAmazonFinancialRefresh::financialCases($this->persistence,250) as $case){
            $caseCount++;
            $caseId=(int)($case['id'] ?? 0);
            if($caseId<1)continue;
            $events=$this->persistence->events->eventsForCase($caseId);
            $transactions=$worker->transactionsFromEvents($events);
            $result=$worker->reconcileCase($case,$transactions);
            $apply=$worker->shouldUpdateCase($case,$transactions);
            $financialAudit[]=SvAmazonReturnsRuntimeAudit::financial($case,$transactions,$result,$apply);
            if(!$apply)continue;
            if($transactions!==[])$withTransactions++;
            $terminal=$result['state']===SvAmazonReturnStates::RECOVERED;
            $this->persistence->cases->update($caseId,[
                'reconciled_credit_amount'=>$result['credit_amount'],
                'state'=>$result['state'],
                'terminal_reason'=>$terminal?'FINANCIAL_RECOVERED':null,
                'closed_at'=>$terminal
                    ? ($case['closed_at'] ?? gmdate('Y-m-d H:i:s'))
                    : null,
            ]);
            $updated++;
        }
        return [
            'status'=>'OK','cases'=>$caseCount,
            'with_transactions'=>$withTransactions,'updated'=>$updated,
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
            return ['status'=>'REMOTE_POLLING','reason'=>'WINDOWS_BRIDGE_OWNS_OUTBOX'];
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
