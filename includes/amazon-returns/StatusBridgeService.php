<?php
declare(strict_types=1);

require_once __DIR__ . '/RemoteBridge.php';
require_once __DIR__ . '/SafeTStatusService.php';
require_once __DIR__ . '/SellerSupportStatus.php';
require_once __DIR__ . '/TenantPersistence.php';
require_once __DIR__ . '/RecoveryWindow.php';
require_once __DIR__ . '/CaseConsultation.php';

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
        $this->p->outbox->supersedeExpiredRecoveryReads('RECOVERY_WINDOW_EXPIRED_D90');
        $this->p->outbox->supersedeTerminalReadJobs('TERMINAL_CASE_NO_LONGER_REQUIRES_READ');
        $this->p->outbox->supersedeDiscoveryJobsForKnownSafeT('SAFE_T_ID_ALREADY_KNOWN');
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
        // The canonical support_case_id may refer to a closed older thread.
        // Read all *source-backed* sibling threads for currently open reviews
        // before the older primary; this only enqueues authenticated READ jobs.
        // A Gmail reference is not a provider status until Seller Central
        // confirms its order/claim identity through the existing worker.
        foreach($this->p->reviews->openQueue() as $review){
            $caseId=(int)($review['case_id']??0);
            if($caseId<1 || $this->p->outbox->hasActive($caseId,'SELLER_SUPPORT_READ'))continue;
            $case=$this->p->cases->find($caseId);
            if(!is_array($case) || trim((string)($case['closed_at']??''))!=='')continue;
            $timeline=$this->p->events->eventsForCase($caseId);
            foreach(SvAmazonCaseConsultation::supportCaseReferences($case,$timeline) as $ref){
                if(($ref['is_primary']??false)===true)continue;
                $refId=trim((string)($ref['id']??''));
                if(preg_match('/^\d{8,14}$/D',$refId)!==1)continue;
                $key=SvAmazonSellerSupportStatus::readKeyForTimeline($caseId,$refId,$now,$timeline);
                $created=$this->p->outbox->enqueueResult('SELLER_SUPPORT_READ',$caseId,[
                    'case_id'=>$caseId,
                    'order_id'=>(string)($case['amazon_order_id']??''),
                    'safe_t_id'=>$case['safe_t_id']??null,
                    'support_case_id'=>$refId,
                    'read_only'=>true,
                ],$key);
                if(($created['enqueued']??false)===true){$ensured++;break;}
            }
        }
        foreach($this->p->cases->casesWithSupportCaseId(250) as $case){
            $caseId=(int)($case['id'] ?? 0);
            $supportCaseId=trim((string)($case['support_case_id'] ?? ''));
            if($caseId<1 || preg_match('/^\d{8,14}$/',$supportCaseId)!==1)continue;
            if($this->p->outbox->hasActive($caseId,'SELLER_SUPPORT_READ'))continue;
            $key=SvAmazonSellerSupportStatus::readKeyForTimeline($caseId,$supportCaseId,$now,$this->p->events->eventsForCase($caseId));
            $this->p->outbox->enqueue('SELLER_SUPPORT_READ',$caseId,[
                'case_id'=>$caseId,
                'order_id'=>(string)($case['amazon_order_id'] ?? ''),
                'safe_t_id'=>$case['safe_t_id'] ?? null,
                'support_case_id'=>$supportCaseId,
                'read_only'=>true,
            ],$key);
            $ensured++;
        }
        // Once daily, source-backed buyer-delivery checks for pending AI reviews.
        // This is a read-only Seller Central job, never a SAFE-T submission.
        $shipScope='seller-central-order-shipment-v1|'.$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        foreach($this->p->reviews->openQueue() as $review){
            $caseId=(int)($review['case_id']??0);
            if($caseId<1)continue;
            $facts=is_array($review['context']['facts']??null)?$review['context']['facts']:[];
            if(($facts['customer_delivery_confirmed']??false)===true)continue;
            if($this->p->outbox->hasActive($caseId,'ORDER_DELIVERY_READ'))continue;
            $case=$this->p->cases->find($caseId);
            if(!is_array($case))continue;
            $order=trim((string)($case['amazon_order_id']??''));
            $item=trim((string)($case['amazon_order_item_id']??''));
            if(preg_match('/^\\d{3}-\\d{7}-\\d{7}$/D',$order)!==1
                || preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$item)!==1)continue;
            $key=$this->p->outbox->deterministicKey('ORDER_DELIVERY_READ',$caseId,$shipScope);
            $created=$this->p->outbox->enqueueResult('ORDER_DELIVERY_READ',$caseId,[
                'case_id'=>$caseId,'order_id'=>$order,'order_item_id'=>$item,
                'read_only'=>true,'purpose'=>'AI_SHIPPING_EVIDENCE',
            ],$key);
            if($created['enqueued'])$ensured++;
        }
        $scope='seller-central-order-discovery-v1|'.$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        // A single LIMIT 250 would permanently omit new high-ID cases once
        // the active unmatched population exceeds one page. Keyset paging
        // covers every candidate without creating a second reader/worker.
        $cursor=0;
        do{
            $page=$this->p->cases->casesWithoutSafeTId(250,$cursor);
            foreach($page as $case){
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
            if($page===[])break;
            $last=$page[count($page)-1];
            $nextCursor=(int)($last['id']??0);
            if($nextCursor<=$cursor)throw new RuntimeException('SAFE_T_DISCOVERY_CURSOR_NOT_ADVANCING');
            $cursor=$nextCursor;
        }while(count($page)===250);
        return $ensured;
    }

    /**
     * Finances-confirmed refunds since the last verified claim are checked
     * against Seller Central via the EXISTING read worker, never by opening a
     * new SAFE-T. Reuse the same daily idempotency key as ensureJobs().
     * Ambiguous multi-item orders must not inherit a claim by order alone.
     *
     * @param list<string> $orderIds
     * @return array{enqueued:int,already_active:int,locally_linked:int,unmapped:int,ambiguous:int,closed:int,expired:int}
     */
    public function ensureRefundClaimChecks(array $orderIds,DateTimeImmutable $now): array
    {
        $stats=['enqueued'=>0,'already_active'=>0,'locally_linked'=>0,
            'unmapped'=>0,'ambiguous'=>0,'closed'=>0,'expired'=>0];
        $seen=[];
        $scope='seller-central-order-discovery-v1|'
            .$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        foreach($orderIds as $orderId){
            if(!is_string($orderId)
                || preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1){
                throw new InvalidArgumentException('Refund reconciliation requires a valid order ID.');
            }
            if(isset($seen[$orderId]))continue;
            $seen[$orderId]=true;
            $cases=$this->p->cases->forOrder($orderId);
            if($cases===[]){$stats['unmapped']++;continue;}
            if(count($cases)!==1){$stats['ambiguous']++;continue;}
            $case=$cases[0];
            $caseId=(int)($case['id']??0);
            if($caseId<1)throw new UnexpectedValueException('Refund case has invalid identity.');
            if(SvAmazonRecoveryWindow::expired($case,$now)){$stats['expired']++;continue;}
            if(trim((string)($case['safe_t_id']??''))!==''){
                // The standard SAFE_T_READ path independently refreshes its status.
                $stats['locally_linked']++;
                continue;
            }
            if(($case['closed_at']??null)!==null){$stats['closed']++;continue;}
            if($this->p->outbox->hasActive($caseId,'SAFE_T_DISCOVERY')){
                $stats['already_active']++;
                continue;
            }
            $key=$this->p->outbox->deterministicKey('SAFE_T_DISCOVERY',$caseId,$scope);
            $added=$this->p->outbox->enqueueResult('SAFE_T_DISCOVERY',$caseId,[
                'case_id'=>$caseId,
                'order_id'=>$orderId,
                'order_item_id'=>(string)($case['amazon_order_item_id']??''),
                'read_only'=>true,
                'lookback_days'=>90,
            ],$key);
            if($added['enqueued']===true)$stats['enqueued']++;
        }
        return $stats;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function discoverSupportActions(array $items,DateTimeImmutable $now): array
    {
        if(count($items)>50)return ['status'=>'TOO_MANY_ITEMS','http_status'=>413];
        $accepted=0;$skipped=0;$seen=[];
        foreach($items as $item){
            if(!is_array($item)){$skipped++;continue;}
            $orderId=trim((string)($item['order_id']??''));
            if(preg_match('/^\d{3}-\d{7}-\d{7}$/D',$orderId)!==1){$skipped++;continue;}
            try{$support=SvAmazonSellerSupportStatus::normalize($item);}catch(Throwable){$skipped++;continue;}
            $normalizedStatus=preg_replace('/[^A-Z0-9]+/','',strtoupper($support['case_status'])) ?? '';
            if(!in_array($normalizedStatus,['PENDINGSELLERACTION','AWAITINGSELLERACTION','PENDINGMERCHANTACTION','AWAITINGMERCHANTACTION'],true)){
                $skipped++;continue;
            }
            $case=$this->p->cases->findSingleByOrder($orderId);
            if(!is_array($case)){$skipped++;continue;}
            $caseId=(int)($case['id']??0);
            if($caseId<1){$skipped++;continue;}
            $dedupe=$caseId.'|'.$support['case_id'].'|'.$support['content_fingerprint'];
            if(isset($seen[$dedupe]))continue;
            $seen[$dedupe]=true;
            $timeline=$this->p->events->eventsForCase($caseId);
            $plan=SvAmazonSellerSupportStatus::observationPlan($caseId,$support,$timeline);
            if($plan['append']){
                $this->p->events->append([
                    'case_id'=>$caseId,
                    'event_type'=>'SELLER_SUPPORT_STATUS_OBSERVED',
                    'source'=>'SELLER_CENTRAL',
                    'source_event_id'=>$support['case_id'].'|'.$support['case_status'].'|ACTION_DISCOVERY',
                    'idempotency_key'=>$plan['idempotency_key'],
                    'occurred_at'=>$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'payload'=>$support+['discovered_via'=>'CASE_LOBBY_ACTION_SCAN'],
                    'evidence_sha256'=>null,
                ]);
                $accepted++;
            }
        }
        return ['status'=>'OK','accepted'=>$accepted,'skipped'=>$skipped];
    }

    /** @return array<string,mixed> */
    public function pull(DateTimeImmutable $now): array
    {
        $ensured=$this->ensureJobs($now);
        // Read existing SAFE-T claims and support cases first: a decision or
        // appeal/reply deadline must not wait behind hundreds of unverified
        // order-discovery searches. The fallback still drains every discovery.
        $rows=$this->p->outbox->claimBatch(1,['SAFE_T_READ','SELLER_SUPPORT_READ']);
        if($rows===[]){
            $rows=$this->p->outbox->claimBatch(1,['ORDER_DELIVERY_READ']);
        }
        if($rows===[]){
            // Under heavy daily discovery backlogs, read the D+45..D+89 cases
            // closest to D+90 first. This does not submit a claim or bypass
            // the ordinary transactional claimBatch lease/idempotency.
            $urgentIds=$this->p->outbox->urgentDiscoveryCaseIds(50);
            if($urgentIds!==[]){
                $rows=$this->p->outbox->claimBatch(1,['SAFE_T_DISCOVERY'],$urgentIds,true);
            }
        }
        if($rows===[]){
            // Refunds older than the 90-day list window are left for the D+90
            // sweep instead of re-consuming the shared drain budget every cycle.
            $rows=$this->p->outbox->claimBatch(1,['SAFE_T_DISCOVERY'],null,false,true);
        }
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
            || !in_array($kind,['SAFE_T_READ','SAFE_T_DISCOVERY','SELLER_SUPPORT_READ','SELLER_SUPPORT_UPDATE','ORDER_DELIVERY_READ'],true)
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
        if($kind==='ORDER_DELIVERY_READ' && $status==='ACCEPTED' && is_array($result['shipment']??null)){
            return $this->completeShipmentObservation($row,$result);
        }
        if($kind==='SELLER_SUPPORT_READ' && $status==='ACCEPTED' && is_array($result['support'] ?? null)){
            return $this->completeSupportObservation($row,$result);
        }
        if(in_array($kind,['SELLER_SUPPORT_READ','SELLER_SUPPORT_UPDATE'],true) && $status==='NOT_FOUND'
            && (string)($result['reason'] ?? '')==='SELLER_SUPPORT_CASE_IDENTITY_MISMATCH'){
            return $this->completeSupportIdentityMismatch($row,$result);
        }
        // A successful browser request is not a verified decision. An unreadable
        // claim status may hide an appeal/reply deadline; retry the same scoped
        // read without posting a false-green observation or external write.
        if($kind==='SAFE_T_READ' && $status==='ACCEPTED'
            && is_array($result['read'] ?? null)
            && strtoupper(trim((string)($result['read']['claim_status'] ?? 'UNKNOWN')))==='UNKNOWN'){
            $next=(new DateTimeImmutable('now',new DateTimeZone('UTC')))
                ->modify('+1 hour');
            $this->p->outbox->defer(
                $jobId,$next,'UI_DRIFT: SAFE_T_STATUS_UNRECOGNIZED',true
            );
            return [
                'status'=>'ACK','job_id'=>$jobId,'result_status'=>'UI_DRIFT',
                'reason'=>'SAFE_T_STATUS_UNRECOGNIZED',
                'retry_scheduled'=>true,'completed'=>false,
            ];
        }
        if($status==='ACCEPTED' && is_array($result['read'] ?? null)){
            return $kind==='SAFE_T_DISCOVERY'
                ? $this->completeDiscovery($row,$result)
                : $this->completeObservation($row,$result);
        }
        if($kind==='SAFE_T_DISCOVERY' && $status==='NOT_FOUND'
            && (string)($result['reason']??'')==='OFFICIAL_90_DAY_COMPLETE_LIST_NO_MATCH'){
            $case=$this->p->cases->find((int)$row['case_id']);
            if(is_array($case) && self::verifiedCompleteClaimListEvidence($result,$case)){
                return $this->completeReadOnlyNoMatch($row,$result,$case);
            }
        }
        if($kind==='SAFE_T_DISCOVERY' && $status==='NOT_FOUND'
            && (string)($result['reason']??'')==='SAFE_T_DISCOVERY_REFUND_NOT_IN_LISTED_WINDOW'){
            $case=$this->p->cases->find((int)$row['case_id']);
            if(is_array($case) && self::verifiedListViewEvidence($result,$case)){
                return $this->completeReadOnlyNoMatch($row,$result,$case,'WINDOW_NOT_COVERING_REFUND');
            }
        }
        if($kind==='SAFE_T_DISCOVERY' && $status==='NOT_FOUND'){
            // A search of the visible first page is not evidence of complete
            // Seller Central history. Older workers may still emit NOT_FOUND.
            $next=(new DateTimeImmutable('now',new DateTimeZone('UTC')))
                ->modify('+6 hours');
            $this->p->outbox->defer(
                $jobId,$next,'SAFE_T_DISCOVERY_NOT_EXHAUSTIVE',true
            );
            return [
                'status'=>'ACK','job_id'=>$jobId,'result_status'=>'NOT_FOUND',
                'retry_scheduled'=>true,'completed'=>false,
            ];
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

    /** @param array<string,mixed> $row @param array<string,mixed> $case */
    private function supportCaseIdForRow(array $row,array $case): string
    {
        $payload=is_array($row['payload']??null)?$row['payload']:[];
        $scoped=trim((string)($payload['support_case_id']??''));
        if(preg_match('/^\\d{8,14}$/D',$scoped)===1)return $scoped;
        $primary=trim((string)($case['support_case_id']??''));
        return preg_match('/^\\d{8,14}$/D',$primary)===1?$primary:'';
    }

    /** Seller Central shipment read: order identity and item identity are mandatory. */
    private function completeShipmentObservation(array $row,array $result): array
    {
        $shipment=$result['shipment'];
        $caseId=(int)($row['case_id']??0);
        $case=$this->p->cases->find($caseId);
        if(!is_array($case))return ['status'=>'JOB_NOT_FOUND','http_status'=>404];
        $expectedOrder=trim((string)($case['amazon_order_id']??''));
        $expectedItem=trim((string)($case['amazon_order_item_id']??''));
        if($expectedOrder==='' || $expectedItem===''
            || !hash_equals($expectedOrder,(string)$shipment['order_id'])
            || !hash_equals($expectedItem,(string)$shipment['order_item_id'])){
            throw new RuntimeException('Seller Central shipment read identity did not match the scoped order item.');
        }
        $snapshot=$result['evidence']['snapshot_sha256']??null;
        if(!is_string($snapshot) || preg_match('/^[a-f0-9]{64}$/iD',$snapshot)!==1)$snapshot=null;
        $ids=$shipment['customer_tracking_ids'];
        // No shipping facts means the browser was able to read the order,
        // but proved nothing; acknowledge and retry tomorrow without inventing
        // delivery, non-delivery, or physical-return evidence.
        $record=$ids!==[] && $snapshot!==null;
        $db=$this->p->db();$db->beginTransaction();
        try{
            $this->p->cases->assertOwned($caseId);
            if(!$record && $snapshot!==null){
                // A completed visit with no reliable tracking is auditable,
                // but deliberately cannot change customer-delivery facts.
                $this->p->events->append([
                    'case_id'=>$caseId,
                    'event_type'=>'CUSTOMER_SHIPMENT_LOOKUP_ATTEMPTED',
                    'source'=>'SELLER_CENTRAL',
                    'source_event_id'=>'shipment-attempt:'.(string)$row['id'],
                    'idempotency_key'=>SvAmazonTenantReturnEventStore::deterministicKey(
                        'seller-central-shipment-attempt',(string)$caseId,gmdate('Y-m-d')
                    ),
                    'occurred_at'=>gmdate('Y-m-d H:i:s'),
                    'payload'=>[
                        'order_id'=>$expectedOrder,
                        'order_item_id'=>$expectedItem,
                        'outcome'=>'NO_TRACKING_PROOF',
                        'tracking_button_count'=>min(100,max(0,(int)($result['evidence']['tracking_button_count']??0))),
                        'tracking_dialog_detected'=>($result['evidence']['tracking_dialog_detected']??false)===true,
                    ],
                    'evidence_sha256'=>$snapshot,
                ]);
            }
            if($record){
                $canonical=json_encode([
                    'status'=>$shipment['customer_delivery_status'],
                    'ids'=>$ids,
                    'carriers'=>$shipment['customer_delivery_carriers'],
                    'confirmed'=>$shipment['customer_delivery_confirmed'],
                ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                $key=SvAmazonTenantReturnEventStore::deterministicKey(
                    'seller-central-shipment',$expectedOrder,$expectedItem,hash('sha256',$canonical)
                );
                $this->p->events->append([
                    'case_id'=>$caseId,
                    'event_type'=>'CUSTOMER_SHIPMENT_STATUS_OBSERVED',
                    'source'=>'SELLER_CENTRAL',
                    'source_event_id'=>'shipment:'.(string)$row['id'],
                    'idempotency_key'=>$key,
                    'occurred_at'=>gmdate('Y-m-d H:i:s'),
                    'payload'=>[
                        'order_id'=>$expectedOrder,
                        'order_item_id'=>$expectedItem,
                        'customer_delivery_status'=>$shipment['customer_delivery_status'],
                        'customer_delivery_confirmed'=>$shipment['customer_delivery_confirmed'],
                        'customer_tracking_ids'=>$ids,
                        'customer_delivery_carriers'=>$shipment['customer_delivery_carriers'],
                        'source'=>'SELLER_CENTRAL_ORDER_DETAILS',
                    ],
                    'evidence_sha256'=>$snapshot,
                ]);
            }
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
            return [
                'status'=>'ACK','job_id'=>(int)$row['id'],
                'result_status'=>'ACCEPTED','completed'=>true,
                'shipment_observation'=>$record?'SOURCE_BACKED':'NO_TRACKING_PROOF',
            ];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
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
            $expected=$this->supportCaseIdForRow($row,$case);
            if($expected==='' || !hash_equals($expected,$mismatchedId)){
                throw new RuntimeException('Seller Support identity changed during mismatch recovery.');
            }
            $clearPrimary=$known!=='' && hash_equals($known,$mismatchedId);
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
                    'binding_cleared'=>$clearPrimary,
                ],
                'evidence_sha256'=>$snapshot,
            ]);
            if($clearPrimary)$this->p->cases->update($caseId,['support_case_id'=>null]);
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
            return [
                'status'=>'ACK','job_id'=>(int)$row['id'],
                'result_status'=>'NOT_FOUND','completed'=>true,
                'support_case_id_cleared'=>$clearPrimary?$mismatchedId:null,
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
        $expected=$this->supportCaseIdForRow($row,$case);
        if($expected==='' || !hash_equals($expected,$support['case_id']))throw new RuntimeException('Seller Support observation identity did not match the scoped case.');
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
    /**
     * A validated full 90-day CLAIM_DATE view only completes the scoped
     * read-only search. It is not a universal no-claim certificate and must
     * never enable external SAFE-T submission or bypass the provider preflight.
     */
    public static function verifiedCompleteClaimListEvidence(
        array $result,array $case,?DateTimeImmutable $now=null
    ):bool{
        if(($result['status']??null)!=='NOT_FOUND'
            || ($result['reason']??null)!=='OFFICIAL_90_DAY_COMPLETE_LIST_NO_MATCH'
            || ($result['submitted']??false)!==false
            || !empty($result['external_id'])
            || trim((string)($case['safe_t_id']??''))!==''
            || trim((string)($case['closed_at']??''))!=='')return false;
        $e=is_array($result['evidence']??null)?$result['evidence']:[];
        $hash=$e['snapshot_sha256']??null;
        if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)return false;
        $proof=is_array($e['claim_list_proof']??null)?$e['claim_list_proof']:[];
        $order=trim((string)($case['amazon_order_id']??''));
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$order)!==1
            || ($proof['order_id']??null)!==$order
            || ($proof['host']??null)!=='sellercentral.amazon.com.br'
            || ($proof['view']??null)!=='all'
            || ($proof['claim_type']??null)!=='ALL'
            || ($proof['fulfillment']??null)!=='ALL'
            || ($proof['date_filter_type']??null)!=='CLAIM_DATE'
            || ($proof['date_filter_days']??null)!==90
            || ($proof['page_size']??null)!==100
            || ($proof['page_start_key']??null)!==0
            || ($proof['range_start']??null)!==1)return false;
        $count=$proof['total']??null;
        if(!is_int($count)||$count<1||$count>100)return false;
        foreach(['range_end','rendered','parsed_orders','parsed_claims','unique_claims'] as $field){
            if(($proof[$field]??null)!==$count)return false;
        }
        $tz=new DateTimeZone('UTC');
        $startRaw=$proof['start_date']??null;$endRaw=$proof['end_date']??null;
        $refundRaw=$case['refund_at']??null;
        if(!is_string($startRaw)||!is_string($endRaw)||!is_string($refundRaw))return false;
        $start=DateTimeImmutable::createFromFormat('!m/d/Y',$startRaw,$tz);
        $end=DateTimeImmutable::createFromFormat('!m/d/Y',$endRaw,$tz);
        $refunded=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$refundRaw,$tz);
        if(!$start||!$end||!$refunded
            ||$start->format('m/d/Y')!==$startRaw||$end->format('m/d/Y')!==$endRaw
            ||$refunded->format('Y-m-d H:i:s')!==$refundRaw)return false;
        $now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        $window=$end->getTimestamp()-$start->getTimestamp();
        return $window>=88*86400 && $window<=92*86400
            && $start<=$refunded && $refunded<$end->modify('+1 day')
            && $end>=$now->modify('-2 days') && $end<=$now->modify('+2 days');
    }

    /**
     * The official claim list was read completely for the case's order, but the
     * case refund date is unknown or outside the listed window. This is only an
     * observation: no absence, eligibility or write authorization is implied and
     * official reconciliation (Finances/D+45 preflight) still decides the case.
     */
    public static function verifiedListViewEvidence(
        array $result,array $case,?DateTimeImmutable $now=null
    ):bool{
        if(($result['status']??null)!=='NOT_FOUND'
            || ($result['reason']??null)!=='SAFE_T_DISCOVERY_REFUND_NOT_IN_LISTED_WINDOW'
            || ($result['submitted']??false)!==false
            || !empty($result['external_id'])
            || trim((string)($case['safe_t_id']??''))!==''
            || trim((string)($case['closed_at']??''))!=='')return false;
        $e=is_array($result['evidence']??null)?$result['evidence']:[];
        $hash=$e['snapshot_sha256']??null;
        if(!is_string($hash)||preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)return false;
        $proof=is_array($e['claim_list_proof']??null)?$e['claim_list_proof']:[];
        $order=trim((string)($case['amazon_order_id']??''));
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$order)!==1
            || ($proof['order_id']??null)!==$order
            || ($proof['host']??null)!=='sellercentral.amazon.com.br'
            || ($proof['view']??null)!=='all'
            || ($proof['claim_type']??null)!=='ALL'
            || ($proof['fulfillment']??null)!=='ALL'
            || ($proof['date_filter_type']??null)!=='CLAIM_DATE'
            || ($proof['date_filter_days']??null)!==90
            || ($proof['page_size']??null)!==100
            || ($proof['page_start_key']??null)!==0
            || ($proof['range_start']??null)!==1)return false;
        $count=$proof['total']??null;
        if(!is_int($count)||$count<1||$count>100)return false;
        foreach(['range_end','rendered','parsed_orders','parsed_claims','unique_claims'] as $field){
            if(($proof[$field]??null)!==$count)return false;
        }
        $tz=new DateTimeZone('UTC');
        $startRaw=$proof['start_date']??null;$endRaw=$proof['end_date']??null;
        if(!is_string($startRaw)||!is_string($endRaw))return false;
        $start=DateTimeImmutable::createFromFormat('!m/d/Y',$startRaw,$tz);
        $end=DateTimeImmutable::createFromFormat('!m/d/Y',$endRaw,$tz);
        if(!$start||!$end||$start->format('m/d/Y')!==$startRaw||$end->format('m/d/Y')!==$endRaw)return false;
        $now=($now??new DateTimeImmutable('now',$tz))->setTimezone($tz);
        $window=$end->getTimestamp()-$start->getTimestamp();
        return $window>=88*86400 && $window<=92*86400
            && $end>=$now->modify('-2 days') && $end<=$now->modify('+2 days');
    }

    /** An immutable audit observation, NOT a provider absence/eligibility grant. */
    private function completeReadOnlyNoMatch(array $row,array $result,array $case,string $coverage='REFUND_IN_LISTED_WINDOW'):array
    {
        $caseId=(int)$row['case_id'];
        $proof=$result['evidence']['claim_list_proof'];
        $db=$this->p->db();$db->beginTransaction();
        try{
            $this->p->cases->assertOwned($caseId);
            $fresh=$this->p->cases->find($caseId);
            $stillValid=is_array($fresh) && ($coverage==='WINDOW_NOT_COVERING_REFUND'
                ? self::verifiedListViewEvidence($result,$fresh)
                : self::verifiedCompleteClaimListEvidence($result,$fresh));
            if(!$stillValid){
                throw new RuntimeException('Seller Central list proof changed before readback.');
            }
            $this->p->events->append([
                'case_id'=>$caseId,
                'event_type'=>'SAFE_T_DISCOVERY_COMPLETE_LIST_OBSERVED',
                'source'=>'SELLER_CENTRAL',
                'source_event_id'=>'safet-list-read:'.(string)$row['id'],
                'idempotency_key'=>SvAmazonTenantReturnEventStore::deterministicKey(
                    'seller-central-safet-complete-list',(string)$caseId,(string)$row['id']
                ),
                'occurred_at'=>gmdate('Y-m-d H:i:s'),
                'payload'=>[
                    'order_id'=>$fresh['amazon_order_id'],
                    'search_scope'=>'COMPLETE_OFFICIAL_90_DAY_LIST',
                    'start_date'=>$proof['start_date'],
                    'end_date'=>$proof['end_date'],
                    'listed_claim_count'=>$proof['total'],
                    'refund_window_coverage'=>$coverage,
                    'requires_official_reconciliation'=>$coverage==='WINDOW_NOT_COVERING_REFUND',
                    'provider_claim_absence_certified'=>false,
                    'external_write_authorized'=>false,
                ],
                'evidence_sha256'=>$result['evidence']['snapshot_sha256'],
            ]);
            $this->p->outbox->markSucceeded((int)$row['id']);
            $db->commit();
            return ['status'=>'ACK','job_id'=>(int)$row['id'],
                'result_status'=>'LIST_READ_COMPLETED_NO_MATCH',
                'completed'=>true,'external_write_authorized'=>false];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            throw $e;
        }
    }

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
