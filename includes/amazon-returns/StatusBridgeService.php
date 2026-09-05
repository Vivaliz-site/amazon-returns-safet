<?php
declare(strict_types=1);

require_once __DIR__ . '/RemoteBridge.php';
require_once __DIR__ . '/SafeTStatusService.php';
require_once __DIR__ . '/TenantPersistence.php';

final class SvAmazonReturnsStatusBridgeService
{
    public function __construct(private SvAmazonTenantPersistence $p) {}

    /** @return array<string,mixed> */
    public function heartbeat(SvAmazonReturnsConfig $config): array
    {
        return [
            'status'=>'OK',
            'tenant_id'=>$this->p->context()->tenantId(),
            'amazon_connection_id'=>$this->p->context()->amazonConnectionId(),
            'read_only'=>true,
            'enabled'=>$config->enabled(),
            'mode'=>$config->mode(),
            'server_time'=>gmdate(DATE_ATOM),
        ];
    }

    public function ensureJobs(DateTimeImmutable $now): int
    {
        $ensured=0;
        foreach($this->p->cases->casesWithSafeTId(250) as $case){
            $caseId=(int)($case['id'] ?? 0);
            $safeTId=trim((string)($case['safe_t_id'] ?? ''));
            if($caseId<1 || $safeTId==='')continue;
            if($this->p->outbox->hasActive($caseId,'SAFE_T_READ'))continue;
            $key=SvAmazonSafeTStatusService::readKey($caseId,$safeTId,$now);
            $this->p->outbox->enqueue('SAFE_T_READ',$caseId,[
                'case_id'=>$caseId,
                'order_id'=>(string)($case['amazon_order_id'] ?? ''),
                'order_item_id'=>(string)($case['amazon_order_item_id'] ?? ''),
                'safe_t_id'=>$safeTId,
                'read_only'=>true,
            ],$key);
            $ensured++;
        }
        return $ensured;
    }

    /** @return array<string,mixed> */
    public function pull(DateTimeImmutable $now): array
    {
        $ensured=$this->ensureJobs($now);
        $rows=$this->p->outbox->claimBatch(1,['SAFE_T_READ']);
        if($rows===[])return ['status'=>'NO_JOB','ensured'=>$ensured];
        $row=$rows[0];
        $case=$this->p->cases->find((int)$row['case_id']);
        if(!is_array($case)){
            $this->p->outbox->markFailed($row,'SAFE-T status case missing in tenant scope.');
            return ['status'=>'NO_JOB','ensured'=>$ensured,'reason'=>'SCOPED_CASE_MISSING'];
        }
        return [
            'status'=>'JOB',
            'job'=>SvAmazonReturnsRemoteBridge::jobEnvelope($row,$case,[]),
            'ensured'=>$ensured,
        ];
    }

    /** @return array<string,mixed> */
    public function acceptResult(int $jobId,string $idempotencyKey,array $input): array
    {
        $idempotencyKey=strtolower(trim($idempotencyKey));
        if($jobId<1 || preg_match('/^[a-f0-9]{64}$/',$idempotencyKey)!==1){
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
            || strtoupper((string)($row['kind'] ?? ''))!=='SAFE_T_READ'
            || !hash_equals((string)$row['idempotency_key'],$idempotencyKey)){
            return ['status'=>'JOB_NOT_FOUND','http_status'=>404];
        }
        if((string)$row['status']==='SUCCEEDED'){
            return ['status'=>'ACK','already_completed'=>true];
        }
        if((string)$row['status']!=='PROCESSING'){
            return ['status'=>'JOB_NOT_PROCESSING','http_status'=>409];
        }

        $status=(string)$result['status'];
        if($status==='ACCEPTED' && is_array($result['read'] ?? null)){
            return $this->completeObservation($row,$result);
        }
        if(in_array($status,['AUTH_REQUIRED','HUMAN_CHALLENGE','UI_DRIFT'],true)){
            $hours=$status==='UI_DRIFT'?6:1;
            $next=(new DateTimeImmutable('now',new DateTimeZone('UTC')))
                ->modify('+'.$hours.' hours');
            $this->p->outbox->defer(
                $jobId,$next,$status.': '.(string)($result['reason'] ?? ''),true
            );
            return [
                'status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,
                'retry_scheduled'=>true,
            ];
        }
        $this->p->outbox->markFailed(
            $row,$status.': '.(string)($result['reason'] ?? 'SAFE_T_READ_FAILED')
        );
        return [
            'status'=>'ACK','job_id'=>$jobId,'result_status'=>$status,'completed'=>false,
        ];
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $result @return array<string,mixed> */
    private function completeObservation(array $row,array $result): array
    {
        $read=$result['read'];
        $caseId=(int)$row['case_id'];
        $case=$this->p->cases->find($caseId);
        if(!is_array($case))return ['status'=>'JOB_NOT_FOUND','http_status'=>404];
        $knownSafeT=trim((string)($case['safe_t_id'] ?? ''));
        if(($read['safe_t_id'] ?? null)!==null
            && !hash_equals($knownSafeT,(string)$read['safe_t_id'])){
            throw new RuntimeException('SAFE-T read-back ID does not match case.');
        }
        $snapshot=$result['evidence']['snapshot_sha256'] ?? null;
        if(!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/i',$snapshot)!==1){
            $snapshot=null;
        }

        $db=$this->p->db();
        $db->beginTransaction();
        try{
            $this->p->cases->assertOwned($caseId);
            $case=$this->p->cases->find($caseId);
            if(!is_array($case))throw new RuntimeException('Owned status case disappeared.');
            if(!hash_equals($knownSafeT,trim((string)($case['safe_t_id']??''))))throw new RuntimeException('Claim identity changed during observation.');
            $events=$this->p->events->eventsForCase($caseId);
            $eventKey=SvAmazonSafeTStatusService::currentObservationKey($caseId,$read,$events);
            $existing=$this->p->events->findIdByIdempotencyKey($eventKey);
            $isNew=$existing===null;
            if($isNew){
                $this->p->events->append([
                    'case_id'=>$caseId,
                    'event_type'=>'SAFE_T_STATUS_OBSERVED',
                    'source'=>'SELLER_CENTRAL',
                    'source_event_id'=>$knownSafeT,
                    'idempotency_key'=>$eventKey,
                    'occurred_at'=>gmdate('Y-m-d H:i:s'),
                    'payload'=>$read,
                    'evidence_sha256'=>$snapshot,
                ]);
            }
            $nextState=SvAmazonSafeTStatusService::nextState(
                (string)$case['state'],(string)$read['claim_status'],
                (bool)($read['appeal_denied'] ?? false)
            );
            $fingerprint=trim((string)($read['decision_fingerprint'] ?? ''));
            $lastFingerprint=trim((string)($case['last_denial_fingerprint'] ?? ''));
            $repeat=(int)($case['repeated_denial_count'] ?? 0);
            if($isNew && (string)$read['claim_status']==='DENIED'){
                $repeat=SvAmazonSafeTStatusService::repeatCount(
                    $lastFingerprint!==''?$lastFingerprint:null,
                    $repeat,
                    $fingerprint!==''?$fingerprint:null
                );
                if($fingerprint!=='')$lastFingerprint=$fingerprint;
            }
            $deadline=$read['appeal_deadline_at'] ?? $case['appeal_deadline_at'];
            if((string)$read['claim_status']==='APPROVED')$deadline=null;
            $patch=[
                'state'=>$nextState,
                'appeal_deadline_at'=>$deadline,
                'last_denial_fingerprint'=>$lastFingerprint!==''?$lastFingerprint:null,
                'repeated_denial_count'=>$repeat,
            ];
            if($isNew && $nextState!==(string)$case['state'] && in_array($nextState,['SAFE_T_DENIED','APPEAL_DENIED_FINAL','SAFE_T_INFO_REQUESTED'],true)){
                $patch['next_action_at']=gmdate('Y-m-d H:i:s');
            }
            $this->p->cases->update($caseId,$patch);
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
            return [
                'status'=>'ACK','job_id'=>(int)$row['id'],
                'claim_status'=>$read['claim_status'],'new_observation'=>$isNew,
            ];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }
}
