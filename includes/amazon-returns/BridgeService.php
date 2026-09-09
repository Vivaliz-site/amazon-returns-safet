<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/RemoteBridge.php';
require_once __DIR__ . '/AmazonRequestedWait.php';
require_once __DIR__ . '/TenantPersistence.php';

final class SvAmazonReturnsBridgeService
{
    public function __construct(
        private SvAmazonTenantPersistence $p,
        private SvAmazonReturnsConfig $config
    ) {}

    /** @return array<string,mixed> */
    public function heartbeat(?string $workerId=null): array
    {
        $worker=$this->workerId($workerId,'write-worker');
        $this->p->cursors->save('SELLER_CENTRAL','write_process_heartbeat',$worker,[
            'status'=>'ALIVE','role'=>'WRITE',
        ]);
        return [
            'status'=>'OK',
            'tenant_id'=>$this->p->context()->tenantId(),
            'amazon_connection_id'=>$this->p->context()->amazonConnectionId(),
            'worker_id'=>$worker,
            'bridge_mode'=>$this->config->sellerCentralBridgeMode(),
            'enabled'=>$this->config->enabled(),
            'mode'=>$this->config->mode(),
            'write_flags'=>$this->config->writeFlags(),
            'server_time'=>gmdate(DATE_ATOM),
        ];
    }

    /** @return array<string,mixed> */
    public function pull(): array
    {
        if($this->config->sellerCentralBridgeMode()!=='polling'){
            return ['status'=>'BRIDGE_MODE_MISMATCH','http_status'=>409];
        }
        $flags=$this->config->writeFlags();
        $trusted=['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'];
        $enabled=array_values(array_filter(
            $trusted,static fn(string $kind):bool=>($flags[$kind] ?? false)===true
        ));
        if($enabled===[]){
            return ['status'=>'NO_JOB','reason'=>'ALL_WRITE_FLAGS_OFF'];
        }
        $rows=$this->p->outbox->claimBatch(1,$enabled);
        if($rows===[])return ['status'=>'NO_JOB'];
        $row=$rows[0];
        $case=$this->p->cases->find((int)$row['case_id']);
        if(!is_array($case)){
            $this->p->outbox->markFailed($row,'Bridge case not found in tenant scope.');
            return ['status'=>'NO_JOB','reason'=>'SCOPED_CASE_MISSING'];
        }
        $job=SvAmazonReturnsRemoteBridge::jobEnvelope($row,$case,$flags);
        foreach([
            'physical_status','state','program','refund_at','seller_debit_at',
            'eligibility_at','appeal_deadline_at',
        ] as $field){
            $job['case'][$field]=$case[$field] ?? null;
        }
        return ['status'=>'JOB','job'=>$job];
    }

    /** @return array<string,mixed> */
    public function acceptResult(int $jobId,string $idempotencyKey,array $input): array
    {
        if($jobId<1 || preg_match('/^[a-f0-9]{64}$/',strtolower(trim($idempotencyKey)))!==1){
            return ['status'=>'INVALID_RESULT','http_status'=>400];
        }
        try{
            $result=SvAmazonReturnsRemoteBridge::validateResult($input);
        }catch(Throwable){
            return [
                'status'=>'INVALID_RESULT','reason'=>'RESULT_CONTRACT_REJECTED',
                'http_status'=>400,
            ];
        }
        $row=$this->p->outbox->findOwned($jobId);
        if(!is_array($row)
            || !hash_equals((string)$row['idempotency_key'],strtolower(trim($idempotencyKey)))){
            return ['status'=>'JOB_NOT_FOUND','http_status'=>404];
        }
        if((string)$row['status']==='SUCCEEDED'){
            return ['status'=>'ACK','already_completed'=>true];
        }
        if((string)$row['status']!=='PROCESSING'){
            return ['status'=>'JOB_NOT_PROCESSING','http_status'=>409];
        }

        $kind=strtoupper((string)$row['kind']);
        $caseId=(int)$row['case_id'];
        $status=(string)$result['status'];
        $externalId=$result['external_id'];
        if($status==='SUPERSEDED'){
            $this->appendResultEvent($row,$result);
            $this->p->outbox->markSuperseded(
                $jobId,(string)($result['reason'] ?? 'SUPERSEDED_BY_CURRENT_CASE_STATE')
            );
            return [
                'status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,'completed'=>true,
            ];
        }
        $success=in_array($status,['ACCEPTED','ALREADY_EXISTS'],true);
        if($success){
            $this->completeSuccess($row,$result);
            return [
                'status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,'completed'=>true,
            ];
        }

        $this->appendResultEvent($row,$result);
        if($status==='BLOCKED_UNTIL'){
            $next=$this->futureDate($result['next_allowed_at'] ?? null,'+6 hours');
            $this->p->outbox->defer(
                $jobId,$next,(string)($result['block_reason'] ?? $status),true
            );
            return [
                'status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,
                'retry_scheduled'=>true,'completed'=>false,
            ];
        }
        if(in_array($status,['AUTH_REQUIRED','HUMAN_CHALLENGE','UI_DRIFT'],true)){
            $fallback=$status==='UI_DRIFT'?'+6 hours':'+1 hour';
            $this->p->outbox->defer(
                $jobId,$this->futureDate(null,$fallback),
                $status.': '.(string)($result['reason'] ?? ''),true
            );
            return [
                'status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,
                'retry_scheduled'=>true,'completed'=>false,
            ];
        }
        $failedRow=$row;
        if(($result['retry_safe'] ?? false)!==true){
            $failedRow['attempt_count']=SvAmazonTenantReturnsOutbox::MAX_ATTEMPTS;
        }
        $this->p->outbox->markFailed(
            $failedRow,$status.': '.(string)($result['reason'] ?? 'REMOTE_BRIDGE_FAILED')
        );
        return [
            'status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,'completed'=>false,
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $result */
    private function completeSuccess(array $row,array $result): void
    {
        $db=$this->p->db();
        $db->beginTransaction();
        try{
            $caseId=(int)$row['case_id'];
            $kind=strtoupper((string)$row['kind']);
            $externalId=$result['external_id'];
            if($kind==='SAFE_T_SUBMIT' && $externalId!==null){
                $this->p->cases->update($caseId,[
                    'safe_t_id'=>$externalId,
                    'state'=>SvAmazonReturnStates::SAFE_T_SUBMITTED,
                ]);
            }elseif($kind==='SAFE_T_APPEAL'){
                $this->p->cases->update($caseId,[
                    'state'=>SvAmazonReturnStates::APPEAL_SUBMITTED,
                ]);
            }elseif(in_array($kind,['SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)){
                $case=$this->p->cases->find($caseId);
                $supportId=$externalId ?? ($case['support_case_id'] ?? null);
                $this->p->cases->update($caseId,[
                    'support_case_id'=>$supportId,
                    'state'=>SvAmazonReturnStates::SUPPORT_ESCALATION,
                ]);
            }
            $this->appendResultEvent($row,$result);
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $result */
    private function appendResultEvent(array $row,array $result): int
    {
        $status=(string)$result['status'];
        $externalId=$result['external_id'];
        $key=hash('sha256',implode('|',[
            'seller-central-result',$this->p->context()->scopeKey(),
            (string)$row['idempotency_key'],$status,(string)($externalId ?? ''),
        ]));
        $snapshot=$result['evidence']['snapshot_sha256'] ?? null;
        if(!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/i',$snapshot)!==1){
            $snapshot=null;
        }
        $rowPayload=is_array($row['payload'] ?? null)?$row['payload']:[];
        $writeSnapshot=is_array($rowPayload['write_snapshot'] ?? null)?$rowPayload['write_snapshot']:[];
        $writeContentSha256=$writeSnapshot['content_sha256'] ?? null;
        if(!is_string($writeContentSha256) || preg_match('/^[a-f0-9]{64}$/i',$writeContentSha256)!==1){
            $writeContentSha256=null;
        }else{
            $writeContentSha256=strtolower($writeContentSha256);
        }
        return $this->p->events->append([
            'case_id'=>(int)$row['case_id'],
            'event_type'=>'SELLER_CENTRAL_ACTION_RESULT',
            'source'=>'SELLER_CENTRAL',
            'source_event_id'=>(string)$row['id'],
            'idempotency_key'=>$key,
            'occurred_at'=>gmdate('Y-m-d H:i:s'),
            'payload'=>[
                'action'=>(string)$row['kind'],
                'resume_scope'=>SvAmazonRequestedWait::jobResumeScope($row),
                'status'=>$status,
                'submitted'=>$result['submitted'],
                'external_id'=>$externalId,
                'retry_safe'=>$result['retry_safe'],
                'block_reason'=>$this->safeText($result['block_reason'] ?? null),
                'next_allowed_at'=>$result['next_allowed_at'],
                'reason'=>$this->safeText($result['reason'] ?? null),
                'outbox_id'=>(int)$row['id'],
                'write_content_sha256'=>$writeContentSha256,
            ],
            'evidence_sha256'=>$snapshot,
        ]);
    }

    private function workerId(?string $value,string $fallback): string
    {
        $value=trim((string)$value);
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$value)===1 ? $value : $fallback;
    }

    private function futureDate(mixed $candidate,string $fallback): DateTimeImmutable
    {
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if(is_scalar($candidate) && trim((string)$candidate)!==''){
            try{
                $date=(new DateTimeImmutable((string)$candidate))->setTimezone(new DateTimeZone('UTC'));
                if($date>$now)return $date;
            }catch(Throwable){
            }
        }
        return $now->modify($fallback);
    }

    private function safeText(mixed $value): ?string
    {
        if(!is_scalar($value))return null;
        $text=trim((string)$value);
        if($text==='')return null;
        $text=preg_replace('/\bBearer\s+[^\s,;]+/i','Bearer [REDACTED]',$text) ?? $text;
        $text=preg_replace(
            '/\b(access_token|refresh_token|client_secret|password|cookie|authorization|mfa|otp)\b\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',$text
        ) ?? $text;
        return mb_substr($text,0,1900,'UTF-8');
    }
}
