<?php
declare(strict_types=1);

require_once __DIR__ . '/RemoteBridge.php';
require_once __DIR__ . '/SafeTStatusService.php';
require_once __DIR__ . '/SellerSupportStatus.php';
require_once __DIR__ . '/TenantPersistence.php';

final class SvAmazonReturnsStatusBridgeService
{
    public function __construct(private SvAmazonTenantPersistence $p) {}

    /** @return array<string,mixed> */
    public function heartbeat(SvAmazonReturnsConfig $config,?string $workerId=null,?string $authStatus=null): array
    {
        $worker=$this->workerId($workerId,'read-worker');
        $this->p->cursors->save('SELLER_CENTRAL','read_process_heartbeat',$worker,[
            'status'=>'ALIVE','role'=>'READ',
        ]);
        $auth=$this->authStatus($authStatus);
        if($auth!==null){
            $this->p->cursors->save('SELLER_CENTRAL','browser_auth',$worker,[
                'status'=>$auth,
            ]);
        }
        return [
            'status'=>'OK',
            'tenant_id'=>$this->p->context()->tenantId(),
            'amazon_connection_id'=>$this->p->context()->amazonConnectionId(),
            'worker_id'=>$worker,
            'auth_status'=>$auth,
            'read_only'=>true,
            'enabled'=>$config->enabled(),
            'mode'=>$config->mode(),
            'server_time'=>gmdate(DATE_ATOM),
        ];
    }

    private function workerId(?string $value,string $fallback): string
    {
        $value=trim((string)$value);
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/D',$value)===1 ? $value : $fallback;
    }

    private function authStatus(?string $value): ?string
    {
        $value=strtoupper(trim((string)$value));
        if($value==='')return null;
        return preg_match('/^[A-Z0-9_:-]{2,64}$/D',$value)===1 ? $value : 'INVALID_AUTH_STATUS';
    }
    public function ensureJobs(DateTimeImmutable $now): int
    {
        $this->p->outbox->supersedeTerminalReadJobs('TERMINAL_CASE_NO_LONGER_REQUIRES_READ');
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
        foreach($this->p->cases->casesWithSupportCaseId(250) as $case){
            $caseId=(int)($case['id'] ?? 0);
            $supportCaseId=trim((string)($case['support_case_id'] ?? ''));
            if($caseId<1 || preg_match('/^\d{8,14}$/',$supportCaseId)!==1)continue;
            if($this->p->outbox->hasActive($caseId,'SELLER_SUPPORT_READ'))continue;
            $key=SvAmazonSellerSupportStatus::readKey($caseId,$supportCaseId,$now);
            $this->p->outbox->enqueue('SELLER_SUPPORT_READ',$caseId,[
                'case_id'=>$caseId,
                'order_id'=>(string)($case['amazon_order_id'] ?? ''),
                'safe_t_id'=>$case['safe_t_id'] ?? null,
                'support_case_id'=>$supportCaseId,
                'read_only'=>true,
            ],$key);
            $ensured++;
        }
        $scope='seller-central-order-discovery-v1|'.$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        foreach($this->p->cases->casesWithoutSafeTId(250) as $case){
            $caseId=(int)($case['id'] ?? 0);
            $orderId=trim((string)($case['amazon_order_id'] ?? ''));
            if($caseId<1 || $orderId==='')continue;
            if($this->p->outbox->hasActive($caseId,'SAFE_T_DISCOVERY'))continue;
            $key=$this->p->outbox->deterministicKey('SAFE_T_DISCOVERY',$caseId,$scope);
            $this->p->outbox->enqueue('SAFE_T_DISCOVERY',$caseId,[
                'case_id'=>$caseId,
                'order_id'=>$orderId,
                'order_item_id'=>(string)($case['amazon_order_item_id'] ?? ''),
                'read_only'=>true,
                'lookback_days'=>90,
            ],$key);
            $ensured++;
        }
        return $ensured;
    }

    /** @return array<string,mixed> */
    public function pull(DateTimeImmutable $now): array
    {
        $ensured=$this->ensureJobs($now);
        $rows=$this->p->outbox->claimBatch(1,['SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ']);
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
        $kind=is_array($row)?strtoupper((string)($row['kind'] ?? '')):'';
        if(!is_array($row)
            || !in_array($kind,['SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ'],true)
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
        if($kind==='SELLER_SUPPORT_READ' && $status==='ACCEPTED' && is_array($result['support'] ?? null)){
            return $this->completeSupportObservation($row,$result);
        }
        if($kind==='SELLER_SUPPORT_READ' && $status==='NOT_FOUND'
            && (string)($result['reason'] ?? '')==='SELLER_SUPPORT_CASE_IDENTITY_MISMATCH'){
            return $this->completeSupportIdentityMismatch($row,$result);
        }
        if($status==='ACCEPTED' && is_array($result['read'] ?? null)){
            return $kind==='SAFE_T_DISCOVERY'
                ? $this->completeDiscovery($row,$result)
                : $this->completeObservation($row,$result);
        }
        if($kind==='SAFE_T_DISCOVERY' && $status==='NOT_FOUND'){
            $this->p->outbox->markSucceeded((int)$row['id']);
            return ['status'=>'ACK','job_id'=>(int)$row['id'],'result_status'=>'NOT_FOUND','completed'=>true];
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
    private function completeSupportIdentityMismatch(array $row,array $result): array
    {
        $caseId=(int)$row['case_id'];
        $mismatchedId=trim((string)($result['external_id'] ?? ''));
        if(preg_match('/^\d{8,14}$/',$mismatchedId)!==1){
            throw new RuntimeException('Seller Support identity mismatch requires the observed case ID.');
        }
        $snapshot=$result['evidence']['snapshot_sha256'] ?? null;
        if(!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/i',$snapshot)!==1)$snapshot=null;
        $db=$this->p->db();$db->beginTransaction();
        try{
            $this->p->cases->assertOwned($caseId);
            $case=$this->p->cases->find($caseId);
            if(!is_array($case))throw new RuntimeException('Owned Seller Support case disappeared.');
            $known=trim((string)($case['support_case_id'] ?? ''));
            if($known==='' || !hash_equals($known,$mismatchedId)){
                throw new RuntimeException('Seller Support identity changed during mismatch recovery.');
            }
            $this->p->events->append([
                'case_id'=>$caseId,
                'event_type'=>'SELLER_SUPPORT_IDENTITY_MISMATCH',
                'source'=>'SELLER_CENTRAL',
                'source_event_id'=>$mismatchedId,
                'idempotency_key'=>SvAmazonTenantReturnEventStore::deterministicKey(
                    'seller-support-identity-mismatch',(string)$caseId,$mismatchedId
                ),
                'occurred_at'=>gmdate('Y-m-d H:i:s'),
                'payload'=>[
                    'support_case_id'=>$mismatchedId,
                    'order_id'=>(string)($case['amazon_order_id'] ?? ''),
                    'reason'=>'SELLER_SUPPORT_CASE_IDENTITY_MISMATCH',
                    'binding_cleared'=>true,
                ],
                'evidence_sha256'=>$snapshot,
            ]);
            $this->p->cases->update($caseId,['support_case_id'=>null]);
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
            return [
                'status'=>'ACK','job_id'=>(int)$row['id'],
                'result_status'=>'NOT_FOUND','completed'=>true,
                'support_case_id_cleared'=>$mismatchedId,
            ];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $result @return array<string,mixed> */
    private function completeSupportObservation(array $row,array $result): array
    {
        $support=SvAmazonSellerSupportStatus::normalize($result['support']);
        $caseId=(int)$row['case_id'];
        $case=$this->p->cases->find($caseId);
        if(!is_array($case))return ['status'=>'JOB_NOT_FOUND','http_status'=>404];
        $known=trim((string)($case['support_case_id'] ?? ''));
        if($known==='' || !hash_equals($known,$support['case_id']))throw new RuntimeException('Seller Support observation identity did not match the scoped case.');
        $snapshot=$result['evidence']['snapshot_sha256'] ?? null;
        if(!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/i',$snapshot)!==1)$snapshot=null;
        $timeline=$this->p->events->eventsForCase($caseId);
        $plan=SvAmazonSellerSupportStatus::observationPlan($caseId,$support,$timeline);
        $db=$this->p->db();$db->beginTransaction();
        try{
            if($plan['append']){
                $this->p->events->append([
                    'case_id'=>$caseId,
                    'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED',
                    'source'=>'SELLER_CENTRAL',
                    'source_event_id'=>$support['case_id'].'|'.$support['case_status'],
                    'idempotency_key'=>$plan['idempotency_key'],
                    'occurred_at'=>gmdate('Y-m-d H:i:s'),
                    'payload'=>$support,
                    'evidence_sha256'=>$snapshot,
                ]);
            }
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
            return [
                'status'=>'ACK','job_id'=>(int)$row['id'],
                'support_case_id'=>$support['case_id'],'support_case_status'=>$support['case_status'],
                'new_observation'=>$plan['append'],
            ];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $row @param array<string,mixed> $result @return array<string,mixed> */
    private function completeDiscovery(array $row,array $result): array
    {
        $read=$result['read'];
        $caseId=(int)$row['case_id'];
        $case=$this->p->cases->find($caseId);
        if(!is_array($case))return ['status'=>'JOB_NOT_FOUND','http_status'=>404];
        $safeTId=trim((string)($read['safe_t_id'] ?? ''));
        $orderId=trim((string)($read['order_id'] ?? ''));
        $expectedOrder=trim((string)($case['amazon_order_id'] ?? ''));
        if(preg_match('/^\d{5}-\d{5}-\d{7}$/',$safeTId)!==1 || $orderId==='' || !hash_equals($expectedOrder,$orderId)){
            throw new RuntimeException('SAFE-T discovery identity did not match the scoped case.');
        }
        $snapshot=$result['evidence']['snapshot_sha256'] ?? null;
        if(!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/i',$snapshot)!==1)$snapshot=null;
        $db=$this->p->db();
        $db->beginTransaction();
        try{
            $this->p->cases->assertOwned($caseId);
            $case=$this->p->cases->find($caseId);
            if(!is_array($case))throw new RuntimeException('Owned discovery case disappeared.');
            $known=trim((string)($case['safe_t_id'] ?? ''));
            if($known!=='' && !hash_equals($known,$safeTId))throw new RuntimeException('SAFE-T identity changed during discovery.');
            $discovered=$known==='';
            if($discovered){
                $this->p->cases->update($caseId,['safe_t_id'=>$safeTId]);
                $this->p->events->append([
                    'case_id'=>$caseId,
                    'event_type'=>'SAFE_T_ID_DISCOVERED',
                    'source'=>'SELLER_CENTRAL',
                    'source_event_id'=>$safeTId,
                    'idempotency_key'=>SvAmazonTenantReturnEventStore::deterministicKey('safe-t-id-discovered',(string)$caseId,$safeTId),
                    'occurred_at'=>gmdate('Y-m-d H:i:s'),
                    'payload'=>['safe_t_id'=>$safeTId,'order_id'=>$orderId,'discovery'=>'SELLER_CENTRAL_ORDER_MATCH'],
                    'evidence_sha256'=>$snapshot,
                ]);
            }
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
            return ['status'=>'ACK','job_id'=>(int)$row['id'],'safe_t_id'=>$safeTId,'discovered'=>$discovered];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
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
            $plan=SvAmazonSafeTStatusService::observationPlan($caseId,$read,$events);
            $eventKey=(string)$plan['idempotency_key'];
            $existing=$this->p->events->findIdByIdempotencyKey($eventKey);
            $isNew=(bool)$plan['append'] && $existing===null;
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
            $patch=SvAmazonSafeTStatusService::projection($case,$read,$isNew,new DateTimeImmutable('now',new DateTimeZone('UTC')));
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
