<?php
declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/DenialAnalyzer.php';
require_once __DIR__ . '/SafeTStatus.php';
require_once __DIR__ . '/AmazonRequestedWait.php';
require_once __DIR__ . '/ReturnActionRouter.php';
require_once __DIR__ . '/RecoveryWindow.php';
require_once __DIR__ . '/SellerSupportStatus.php';

final class SvAmazonSafeTDecisionEngine
{
    public function __construct(private ?SvAmazonDenialAnalyzer $denialAnalyzer = null,private ?DateTimeImmutable $clock=null)
    {
        $this->denialAnalyzer ??= new SvAmazonDenialAnalyzer();
    }

    /** @return array<string,mixed> */
    public function nextAction(array $case,array $timeline,array $policy,?DateTimeImmutable $now=null): array
    {
        $caseId=(int)($case['id'] ?? 0);
        $orderId=trim((string)($case['amazon_order_id'] ?? ''));
        $safeTId=trim((string)($case['safe_t_id'] ?? ''));
        $state=trim((string)($case['state'] ?? ''));

        if($this->hasRecoveredCredit($case))return $this->decision('WAIT','ALREADY_REIMBURSED',$caseId);
        $initiator=(string)($case['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN);
        $deliveryBackedUnknownRefund=$this->deliveryBackedUnknownRefund($case);
        $reimbursementBackedUnknownRefund=$this->partialReimbursementBackedUnknownRefund($case);
        $amazonCustomerRefund=trim((string)($case['refund_at']??''))!=='' && in_array($initiator,[
            SvAmazonRefundInitiators::AMAZON_AUTOMATIC,
            SvAmazonRefundInitiators::AMAZON_CUSTOMER_SERVICE,
            SvAmazonRefundInitiators::AMAZON_INITIATED,
            SvAmazonRefundInitiators::A_TO_Z,
        ],true);
        $customerRefundConfirmed=$amazonCustomerRefund || $deliveryBackedUnknownRefund || $reimbursementBackedUnknownRefund;
        $now ??= $this->clock ?? new DateTimeImmutable('now',new DateTimeZone('UTC'));
        $supportResolution=$this->supportResolutionAction($case,$timeline,$now);
        $supportReason=(string)($supportResolution['reason']??'');
        if($supportResolution!==null && in_array($supportReason,[
            'SUPPORT_REIMBURSEMENT_PROCESSING','SUPPORT_REIMBURSEMENT_PROMISE_DUE','SUPPORT_REIMBURSEMENT_PROMISE_MISSED',
        ],true))return $supportResolution;
        if(SvAmazonRecoveryWindow::expired($case,$now))return $this->decision('WAIT','RECOVERY_WINDOW_EXPIRED',$caseId);
        if($supportResolution!==null)return $supportResolution;
        if($safeTId==='' && $this->sellerAppConfirmedPhysicalReceipt($case,$timeline)){
            return $this->decision('WAIT','SELLER_APP_PHYSICAL_RECEIPT_CONFIRMED',$caseId);
        }
        if($safeTId==='' && ($case['program']??'')==='FBA'){
            return $this->classicFbaRecovery($case,$timeline,$now);
        }
        if($safeTId==='' && (!SvAmazonRefundInitiators::isValid($initiator) || $initiator===SvAmazonRefundInitiators::UNKNOWN)
            && $this->hasReimbursementEvidence($case,$timeline) && !$this->hasFreshConfirmedResidual($case,$timeline,$now)){
            return $this->decision('CHECK_FINANCES','PARTIAL_REIMBURSEMENT_VERIFY_BEFORE_NEW_CLAIM',$caseId);
        }
        if($safeTId!=='' && in_array($state,[SvAmazonReturnStates::SAFE_T_DENIED,SvAmazonReturnStates::APPEAL_REQUIRED],true)
            && $this->hasReimbursementEvidence($case,$timeline)){
            return $this->decision('CHECK_FINANCES','PARTIAL_REIMBURSEMENT_VERIFY_BEFORE_RECOVERY_APPEAL',$caseId);
        }
        if($safeTId==='' && ($policy['eligible']??false)===true){
            if(trim((string)($case['refund_at']??''))==='')return $this->decision('WAIT','REFUND_NOT_CONFIRMED',$caseId);
            if((!SvAmazonRefundInitiators::isValid($initiator) || $initiator===SvAmazonRefundInitiators::UNKNOWN)
                && !$deliveryBackedUnknownRefund && !$reimbursementBackedUnknownRefund){
                return $this->decision('BLOCKED_REVIEW','REFUND_INITIATOR_UNKNOWN',$caseId);
            }
            if(!$customerRefundConfirmed){
                return $this->decision('WAIT','AMAZON_CUSTOMER_REFUND_NOT_CONFIRMED',$caseId);
            }
        }
        $requestedWait=SvAmazonRequestedWait::decision($case,$timeline,$now);
        if($requestedWait!==null)return $requestedWait;
        if($safeTId==='' && $reimbursementBackedUnknownRefund && ($policy['eligible']??false)===true
            && $this->hasFreshConfirmedResidual($case,$timeline,$now)){
            if($this->safeTSubmissionWindowExpired($case,$now)){
                return [
                    'action'=>'SELLER_SUPPORT_OPEN',
                    'reason'=>'SAFE_T_WINDOW_EXPIRED_RESIDUAL_UNPAID',
                    'support_route'=>'GENERAL_ORDER_SUPPORT',
                    'case_id'=>$caseId,
                    'idempotency_key'=>hash('sha256','seller-support-open|safe-t-window-expired|'.$caseId.'|'.$orderId),
                ];
            }
            $policyId=(string)($policy['policy_version_id']??'unknown');
            $eligibilityAt=(string)($policy['eligibility_at']??'unknown');
            return [
                'action'=>'SAFE_T_SUBMIT',
                'reason'=>'PARTIAL_REIMBURSEMENT_RESIDUAL_UNPAID',
                'case_id'=>$caseId,
                'idempotency_key'=>hash('sha256','safe-t-submit|'.$caseId.'|'.$orderId.'|'.$policyId.'|'.$eligibilityAt),
            ];
        }
        $route=SvAmazonReturnActionRouter::decide($case,$timeline,$policy,$now);
        if($route!==null)return $route;

        if($safeTId!=='' && in_array($state,['SAFE_T_DENIED','APPEAL_REQUIRED','SAFE_T_INFO_REQUESTED'],true)){
            $raw=$case['appeal_deadline_at']??null;$deadline=null;
            if(is_string($raw) && preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T]/',$raw,$parts)===1 && checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])){
                try{$candidate=new DateTimeImmutable($raw,new DateTimeZone('UTC'));if(DateTimeImmutable::getLastErrors()===false)$deadline=$candidate;}catch(Throwable){}
            }
            if($deadline===null || $now>$deadline)return $this->decision('HUMAN_REVIEW',$deadline===null?'OFFICIAL_APPEAL_DEADLINE_UNRESOLVED':'OFFICIAL_APPEAL_WINDOW_EXPIRED',$caseId);
        }

        if($safeTId!==''){
            if($state===SvAmazonReturnStates::SAFE_T_INFO_REQUESTED){
                return [
                    'action'=>'SAFE_T_APPEAL',
                    'reason'=>'SAFE_T_INFORMATION_RESPONSE_REQUIRED',
                    'idempotency_key'=>hash('sha256','safe-t-info-response|'.$safeTId.'|'.(string)($case['info_request_fingerprint'] ?? 'current')),
                ];
            }

            if($state===SvAmazonReturnStates::EMAIL_REVIEW_RESPONSE_PENDING){
                return $this->emailReviewResponseAction($case,$timeline,$safeTId,$caseId);
            }

            if($state===SvAmazonReturnStates::APPEAL_DENIED_FINAL){
                $denialContext=SvAmazonSafeTStatus::denialContext($timeline);
                $latestText=trim((string)($case['latest_denial_text'] ?? $denialContext['latest_denial_text']));
                if($latestText==='')return $this->decision('BLOCKED_REVIEW','DENIAL_TEXT_MISSING',$caseId);
                $fingerprint=$this->denialAnalyzer->fingerprint($latestText);
                return [
                    'action'=>'SAFE_T_EMAIL_REVIEW',
                    'reason'=>'APPEAL_DENIED_REQUIRES_DETAILED_EMAIL_REVIEW',
                    'denial_fingerprint'=>$fingerprint,
                    'idempotency_key'=>hash('sha256','safe-t-email-review|'.$safeTId.'|'.$fingerprint),
                ];
            }

            if($state===SvAmazonReturnStates::SUPPORT_ESCALATION){
                $denialContext=SvAmazonSafeTStatus::denialContext($timeline);
                $latestText=trim((string)($case['latest_denial_text'] ?? $denialContext['latest_denial_text']));
                if($latestText==='')return $this->decision('BLOCKED_REVIEW','DENIAL_TEXT_MISSING',$caseId);
                $fingerprint=$this->denialAnalyzer->fingerprint($latestText);
                if($this->hasActiveSupportCase($case,$timeline)){
                    if(($case['new_support_fact'] ?? false)===true){
                        return [
                            'action'=>'SELLER_SUPPORT_UPDATE',
                            'reason'=>'EMAIL_REVIEW_DENIED_NEW_FACT',
                            'support_case_id'=>(string)$case['support_case_id'],
                            'denial_fingerprint'=>$fingerprint,
                            'idempotency_key'=>hash('sha256','support-update|'.(string)$case['support_case_id'].'|'.$fingerprint.'|'.(string)($case['support_fact_hash'] ?? 'new-fact')),
                        ];
                    }
                    return ['action'=>'WAIT','reason'=>'SUPPORT_ESCALATION_ALREADY_ACTIVE','support_case_id'=>(string)$case['support_case_id'],'denial_fingerprint'=>$fingerprint];
                }
                $round=max(1,(int)($case['support_escalation_round'] ?? 1));
                return [
                    'action'=>'SELLER_SUPPORT_OPEN',
                    'reason'=>'EMAIL_REVIEW_DENIED_REQUIRES_SUPPORT',
                    'support_route'=>'GENERAL_ORDER_SUPPORT',
                    'denial_fingerprint'=>$fingerprint,
                    'idempotency_key'=>hash('sha256','support-open|'.$safeTId.'|'.$fingerprint.'|'.$round),
                ];
            }

            if(in_array($state,[SvAmazonReturnStates::SAFE_T_DENIED,SvAmazonReturnStates::APPEAL_REQUIRED],true)){
                $denialContext=SvAmazonSafeTStatus::denialContext($timeline);
                $latestText=trim((string)($case['latest_denial_text'] ?? $denialContext['latest_denial_text']));
                if($latestText==='')return $this->decision('BLOCKED_REVIEW','DENIAL_TEXT_MISSING',$caseId);
                $fingerprint=$this->denialAnalyzer->fingerprint($latestText);
                return [
                    'action'=>'SAFE_T_APPEAL',
                    'reason'=>'SAFE_T_DENIAL_REQUIRES_FIRST_APPEAL',
                    'denial_fingerprint'=>$fingerprint,
                    'idempotency_key'=>hash('sha256','safe-t-appeal|'.$safeTId.'|'.$fingerprint),
                ];
            }
            return $this->decision('WAIT','SAFE_T_ALREADY_EXISTS',$caseId);
        }

        if(trim((string)($case['refund_at']??''))==='')return $this->decision('WAIT','REFUND_NOT_CONFIRMED',$caseId);
        if((!SvAmazonRefundInitiators::isValid($initiator) || $initiator===SvAmazonRefundInitiators::UNKNOWN)
            && !$deliveryBackedUnknownRefund && !$reimbursementBackedUnknownRefund){
            return $this->decision('BLOCKED_REVIEW','REFUND_INITIATOR_UNKNOWN',$caseId);
        }
        if(!$customerRefundConfirmed)return $this->decision('WAIT','AMAZON_CUSTOMER_REFUND_NOT_CONFIRMED',$caseId);
        if(($policy['state'] ?? null)===SvAmazonReturnStates::POLICY_REVIEW_REQUIRED)return $this->decision('BLOCKED_REVIEW','POLICY_REVIEW_REQUIRED',$caseId);
        if(($policy['eligible'] ?? false)!==true)return $this->decision('WAIT','NOT_YET_ELIGIBLE',$caseId);

        $policyId=(string)($policy['policy_version_id'] ?? 'unknown');
        $eligibilityAt=(string)($policy['eligibility_at'] ?? 'unknown');
        $reason=$reimbursementBackedUnknownRefund
            ?'PARTIAL_REIMBURSEMENT_RESIDUAL_UNPAID'
            :($deliveryBackedUnknownRefund?'DELIVERED_CUSTOMER_REFUNDED_UNPAID':'FIRST_ELIGIBLE_ATTEMPT');
        return [
            'action'=>'SAFE_T_SUBMIT',
            'reason'=>$reason,
            'idempotency_key'=>hash('sha256','safe-t-submit|'.$caseId.'|'.$orderId.'|'.$policyId.'|'.$eligibilityAt),
        ];
    }

    private function safeTSubmissionWindowExpired(array $case,DateTimeImmutable $now): bool
    {
        $refundAt=SvAmazonRequestedWait::timestamp($case['refund_at']??null);
        return $refundAt!==null && $now>$refundAt->modify('+75 days');
    }

    /** Guard a learned effect with the same non-negotiable business invariants. */
    public function guardLearnedEffect(array $effect,array $case,array $timeline,array $policy,DateTimeImmutable $now): array
    {
        $caseId=(int)($case['id']??0);$action=(string)($effect['action']??'');$safeTId=trim((string)($case['safe_t_id']??''));
        if($this->hasRecoveredCredit($case))return $this->decision('WAIT','ALREADY_REIMBURSED',$caseId);
        if(SvAmazonRecoveryWindow::expired($case,$now))return $this->decision('WAIT','RECOVERY_WINDOW_EXPIRED',$caseId);
        if($action==='SAFE_T_SUBMIT'){
            if($safeTId!=='')return $this->decision('WAIT','SAFE_T_ALREADY_EXISTS',$caseId);
            if($this->sellerAppConfirmedPhysicalReceipt($case,$timeline))return $this->decision('WAIT','SELLER_APP_PHYSICAL_RECEIPT_CONFIRMED',$caseId);
            $initiator=(string)($case['refund_initiator']??SvAmazonRefundInitiators::UNKNOWN);
            $confirmed=trim((string)($case['refund_at']??''))!=='' && in_array($initiator,[SvAmazonRefundInitiators::AMAZON_AUTOMATIC,SvAmazonRefundInitiators::AMAZON_CUSTOMER_SERVICE,SvAmazonRefundInitiators::AMAZON_INITIATED,SvAmazonRefundInitiators::A_TO_Z],true);
            $confirmed=$confirmed || $this->deliveryBackedUnknownRefund($case);
            if(!$confirmed)return $this->decision('WAIT','AMAZON_CUSTOMER_REFUND_NOT_CONFIRMED',$caseId);
            if(($policy['eligible']??false)!==true)return $this->decision('HUMAN_REVIEW','LEARNED_RULE_D45_GATE_BLOCKED',$caseId);
            if(($case['physical_status']??'')===SvAmazonReturnPhysicalStatuses::RECEIVED_DISCREPANT)return $this->decision('HUMAN_REVIEW','DAMAGED_RETURN_INITIAL_CLAIM_MANUAL_ONLY',$caseId);
        }
        if($action==='WAIT' && trim((string)($effect['parameters']['resolved_date']??''))==='')return $this->decision('HUMAN_REVIEW','LEARNED_RULE_WAIT_DATE_UNRESOLVED',$caseId);
        if($action==='SAFE_T_APPEAL'){
            if(($case['state']??'')===SvAmazonReturnStates::APPEAL_SUBMITTED)return $this->decision('WAIT','APPEAL_ALREADY_SUBMITTED',$caseId);
            $deadline=SvAmazonRequestedWait::timestamp($case['appeal_deadline_at']??null);
            if($safeTId==='' || $deadline===null || $now>$deadline)return $this->decision('HUMAN_REVIEW','LEARNED_RULE_APPEAL_GATE_BLOCKED',$caseId);
        }
        if(in_array($action,['SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true) && $safeTId==='')return $this->decision('HUMAN_REVIEW','LEARNED_RULE_SAFE_T_REQUIRED',$caseId);
        $decision=['action'=>$action,'reason'=>'LEARNED_RULE_APPROVED','case_id'=>$caseId];
        if($action==='SELLER_SUPPORT_OPEN')$decision['support_route']='GENERAL_ORDER_SUPPORT';
        if(isset($effect['parameters']['resolved_date']))$decision['next_action_at']=$effect['parameters']['resolved_date'];
        if(in_array($action,['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true))$decision['idempotency_key']=hash('sha256','learned|'.$action.'|'.$caseId.'|'.$safeTId.'|'.json_encode($effect));
        return $decision;
    }

    /** @return array<string,mixed> */
    private function emailReviewResponseAction(array $case,array $timeline,string $safeTId,int $caseId): array
    {
        $event=$this->latestEmailReviewResponse($timeline);
        if($event===null)return $this->decision('BLOCKED_REVIEW','EMAIL_REVIEW_RESPONSE_MISSING',$caseId);
        $payload=is_array($event['payload'] ?? null)?$event['payload']:[];
        $outcome=strtoupper(trim((string)($payload['review_outcome'] ?? 'UNKNOWN_AMBIGUOUS')));
        $suggested=strtoupper(trim((string)($payload['review_suggested_action'] ?? 'HUMAN_REVIEW')));
        $scope=trim((string)($payload['content_sha256'] ?? '')) ?: hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: 'review');

        if($outcome==='WAIT')return $this->decision('WAIT','EMAIL_REVIEW_PROMISED_FUTURE_ACTION',$caseId);
        if($outcome==='APPROVED')return $this->decision('WAIT','EMAIL_REVIEW_APPROVED_AWAIT_FINANCES',$caseId);
        if($outcome==='UNKNOWN_AMBIGUOUS' && $this->customerNonreceiptContradictedByDelivery($case,$payload)){
            return [
                'action'=>'SAFE_T_EMAIL_REPLY',
                'reason'=>'CUSTOMER_NONRECEIPT_CONTRADICTED_BY_DELIVERY_EVIDENCE',
                'case_id'=>$caseId,
                'idempotency_key'=>hash('sha256','safe-t-email-delivery-contradiction|'.$safeTId.'|'.$scope.'|'.implode(',',(array)($case['customer_tracking_ids']??[]))),
                'review_scope'=>$scope,
            ];
        }
        if($outcome==='UNKNOWN_AMBIGUOUS')return $this->decision('BLOCKED_REVIEW','EMAIL_REVIEW_AMBIGUOUS',$caseId);
        if($outcome==='DENIED_FINAL' && $suggested==='CLOSED_LOSS'){
            return ['action'=>'CLOSE_LOSS','reason'=>'EMAIL_REVIEW_FINAL_NO_REMAINING_PATH','case_id'=>$caseId,'scope'=>$scope];
        }
        if($outcome==='DENIED_FINAL')return $this->decision('BLOCKED_REVIEW','EMAIL_REVIEW_FINAL_REQUIRES_TERMINAL_REVIEW',$caseId);

        if(in_array($outcome,['INFO_REQUESTED','DENIED_ACTIONABLE'],true)){
            if($suggested==='RESPOND_EMAIL'){
                return [
                    'action'=>'SAFE_T_EMAIL_REPLY',
                    'reason'=>$outcome==='INFO_REQUESTED'?'EMAIL_REVIEW_INFORMATION_RESPONSE':'EMAIL_REVIEW_ACTIONABLE_RESPONSE',
                    'idempotency_key'=>hash('sha256','safe-t-email-reply|'.$safeTId.'|'.$scope),
                    'review_scope'=>$scope,
                ];
            }
            if($suggested==='OPEN_SUPPORT'){
                if($this->hasActiveSupportCase($case,$timeline))return $this->decision('WAIT','SUPPORT_ESCALATION_ALREADY_ACTIVE',$caseId);
                return [
                    'action'=>'SELLER_SUPPORT_OPEN',
                    'reason'=>'EMAIL_REVIEW_ANALYZER_SELECTED_SUPPORT',
                    'support_route'=>'GENERAL_ORDER_SUPPORT',
                    'idempotency_key'=>hash('sha256','support-open|'.$safeTId.'|'.$scope),
                    'denial_fingerprint'=>$scope,
                ];
            }
            return $this->decision('BLOCKED_REVIEW','EMAIL_REVIEW_REQUIRES_HUMAN_DECISION',$caseId);
        }
        return $this->decision('BLOCKED_REVIEW','EMAIL_REVIEW_OUTCOME_UNSUPPORTED',$caseId);
    }

    private function latestEmailReviewResponse(array $timeline): ?array
    {
        for($i=count($timeline)-1;$i>=0;$i--){
            $event=$timeline[$i] ?? null;
            if(is_array($event) && ($event['event_type'] ?? '')==='SAFE_T_EMAIL_REVIEW_RESPONSE')return $event;
        }
        return null;
    }

    /** @return array{action:string,reason:string,case_id:int} */
    private function decision(string $action,string $reason,int $caseId): array
    {
        return ['action'=>$action,'reason'=>$reason,'case_id'=>$caseId];
    }

    private function classicFbaRecovery(array $case,array $timeline,DateTimeImmutable $now): array
    {
        $caseId=(int)($case['id']??0);
        $latest=null;$rank=[0,0];
        foreach($timeline as $event){
            if(!is_array($event) || (int)($event['case_id']??0)!==$caseId)continue;
            if(($event['event_type']??'')!=='FINANCIAL_RECONCILIATION_CHECKED' || ($event['source']??'')!=='SP_API_FINANCES')continue;
            try{$at=new DateTimeImmutable((string)($event['occurred_at']??''),new DateTimeZone('UTC'));}catch(Throwable){continue;}
            $r=[$at->getTimestamp(),(int)($event['id']??0)];
            if($r>$rank){$latest=$event;$rank=$r;}
        }
        if($latest===null)return $this->decision('CHECK_FINANCES','CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE',$caseId);
        $at=(new DateTimeImmutable((string)$latest['occurred_at'],new DateTimeZone('UTC')));
        $payload=is_array($latest['payload']??null)?$latest['payload']:[];
        $fresh=$at<=$now && $at>=$now->modify('-2 hours')
            && ($payload['refresh_complete']??false)===true
            && ($payload['ambiguous_reimbursement_transactions']??null)===0
            && ($payload['unsettled_financial_evidence']??null)===false
            && is_numeric($payload['outstanding_amount']??null)
            && (float)$payload['outstanding_amount']>0;
        if(!$fresh || trim((string)($case['refund_at']??''))==='')return $this->decision('CHECK_FINANCES','CLASSIC_FBA_SEPARATE_REIMBURSEMENT_ROUTE',$caseId);
        if($this->hasActiveSupportCase($case,$timeline))return $this->decision('WAIT','SUPPORT_ESCALATION_ALREADY_ACTIVE',$caseId);
        return [
            'action'=>'SELLER_SUPPORT_OPEN',
            'reason'=>'CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION',
            'support_route'=>'FBA_RETURNS_REIMBURSEMENT',
            'case_id'=>$caseId,
            'idempotency_key'=>hash('sha256','classic-fba-support-open|'.$caseId.'|'.(trim((string)($case['support_case_id']??'')) ?: 'initial')),
        ];
    }

    private function sellerAppConfirmedPhysicalReceipt(array $case,array $timeline): bool
    {
        $caseId=(int)($case['id']??0);
        foreach($timeline as $event){
            if(!is_array($event) || (int)($event['case_id']??0)!==$caseId)continue;
            if(($event['event_type']??'')!=='PHYSICAL_RECEIVED' || ($event['source']??'')!=='WAREHOUSE')continue;
            $payload=$event['payload']??null;
            if(!is_array($payload))continue;
            $condition=strtoupper(trim((string)($payload['condition']??'OK')));
            if(!in_array($condition,['OK','INTACT'],true))continue;
            if((int)($payload['quantity']??0)>0)return true;
        }
        return false;
    }

    private function deliveryBackedUnknownRefund(array $case): bool
    {
        return ($case['customer_delivery_confirmed']??false)===true
            && trim((string)($case['refund_at']??''))!==''
            && (string)($case['refund_initiator']??SvAmazonRefundInitiators::UNKNOWN)===SvAmazonRefundInitiators::UNKNOWN;
    }

    private function customerNonreceiptContradictedByDelivery(array $case,array $payload): bool
    {
        if(($case['customer_delivery_confirmed']??false)!==true)return false;
        $tracking=$case['customer_tracking_ids']??[];
        if(!is_array($tracking) || array_values(array_filter(array_map(static fn(mixed $id):string=>trim((string)$id),$tracking)))===[])return false;
        $excerpt=mb_strtolower(trim((string)($payload['review_excerpt']??'')),'UTF-8');
        if($excerpt==='')return false;
        return preg_match('/(?:não|nao)\s+(?:ter\s+)?recebid|não\s+recebeu|nao\s+recebeu|not\s+received|did\s+not\s+receive/u',$excerpt)===1;
    }

    private function partialReimbursementBackedUnknownRefund(array $case): bool
    {
        $expected=(float)($case['expected_reimbursement_amount']??0);
        $credited=(float)($case['reconciled_credit_amount']??0);
        return (string)($case['refund_initiator']??SvAmazonRefundInitiators::UNKNOWN)===SvAmazonRefundInitiators::UNKNOWN
            && trim((string)($case['refund_at']??''))!==''
            && $credited>0.00001 && $expected>$credited+0.00001;
    }

    private function hasFreshConfirmedResidual(array $case,array $timeline,DateTimeImmutable $now): bool
    {
        $caseId=(int)($case['id']??0);
        foreach($timeline as $event){
            if(!is_array($event) || (int)($event['case_id']??0)!==$caseId)continue;
            if(($event['event_type']??'')!=='FINANCIAL_RECONCILIATION_CHECKED' || ($event['source']??'')!=='SP_API_FINANCES')continue;
            try{$at=new DateTimeImmutable((string)($event['occurred_at']??''),new DateTimeZone('UTC'));}catch(Throwable){continue;}
            if($at>$now || $at<$now->modify('-2 hours'))continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            if(($payload['refresh_complete']??false)!==true)continue;
            if(($payload['unsettled_financial_evidence']??null)!==false)continue;
            if(($payload['ambiguous_reimbursement_transactions']??null)!==0)continue;
            if(is_numeric($payload['outstanding_amount']??null) && (float)$payload['outstanding_amount']>0.00001)return true;
        }
        return false;
    }

    private function hasReimbursementEvidence(array $case,array $timeline): bool
    {
        if((float)($case['reconciled_credit_amount']??0)>0.00001)return true;
        $caseId=(int)($case['id']??0);
        foreach($timeline as $event){
            if(!is_array($event) || (int)($event['case_id']??0)!==$caseId)continue;
            if(($event['event_type']??'')!=='SAFE_T_REIMBURSEMENT_OBSERVED')continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            $money=is_array($payload['reimbursed_amount']??null)?$payload['reimbursed_amount']:[];
            if(is_numeric($money['amount']??null) && (float)$money['amount']>0.00001)return true;
        }
        return false;
    }

    private function hasRecoveredCredit(array $case): bool
    {
        $expected=(float)($case['expected_reimbursement_amount'] ?? 0);
        $credited=(float)($case['reconciled_credit_amount'] ?? 0);
        return $expected>0.0 && $credited+0.00001 >= $expected;
    }

    private function latestSupportObservation(array $case,array $timeline): ?array
    {
        $caseId=(int)($case['id']??0);
        $supportId=trim((string)($case['support_case_id']??''));
        if($caseId<1 || $supportId==='')return null;
        $latest=null;$rank=[0,0];
        foreach($timeline as $event){
            if(!is_array($event) || (int)($event['case_id']??0)!==$caseId)continue;
            if(($event['event_type']??'')!=='SELLER_SUPPORT_STATUS_OBSERVED' || ($event['source']??'')!=='SELLER_CENTRAL')continue;
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            if(trim((string)($payload['case_id']??''))!==$supportId)continue;
            try{$at=new DateTimeImmutable((string)($event['occurred_at']??''),new DateTimeZone('UTC'));}catch(Throwable){continue;}
            $candidate=[$at->getTimestamp(),(int)($event['id']??0)];
            if($candidate>$rank){$rank=$candidate;$latest=$event;}
        }
        return $latest;
    }

    private function supportResolutionAction(array $case,array $timeline,DateTimeImmutable $now): ?array
    {
        $event=$this->latestSupportObservation($case,$timeline);
        if($event===null)return null;
        $payload=is_array($event['payload']??null)?$event['payload']:[];
        try{$support=SvAmazonSellerSupportStatus::normalize($payload);}catch(Throwable){return $this->decision('BLOCKED_REVIEW','SELLER_SUPPORT_OBSERVATION_INVALID',(int)($case['id']??0));}
        $resolution=SvAmazonSellerSupportStatus::resolution($support);
        if($resolution==='ACTIVE')return null;
        $caseId=(int)($case['id']??0);
        $safeTId=trim((string)($case['safe_t_id']??''));
        if($resolution==='REIMBURSEMENT_PROCESSING'){
            try{$observed=new DateTimeImmutable((string)($event['occurred_at']??''),new DateTimeZone('UTC'));}catch(Throwable){return $this->decision('BLOCKED_REVIEW','SUPPORT_REIMBURSEMENT_OBSERVED_AT_INVALID',$caseId);}
            $due=SvAmazonSellerSupportStatus::reimbursementDueAt($observed,5);
            if($now<$due)return [
                'action'=>'WAIT','reason'=>'SUPPORT_REIMBURSEMENT_PROCESSING','case_id'=>$caseId,
                'support_case_id'=>$support['case_id'],'next_action_at'=>$due->format('Y-m-d H:i:s'),
            ];
            if($this->hasFreshConfirmedResidual($case,$timeline,$now))return [
                'action'=>'SELLER_SUPPORT_OPEN','reason'=>'SUPPORT_REIMBURSEMENT_PROMISE_MISSED','support_route'=>'FBA_RETURNS_REIMBURSEMENT',
                'case_id'=>$caseId,'idempotency_key'=>hash('sha256','support-promise-missed|'.$caseId.'|'.$support['case_id']),
            ];
            return $this->decision('CHECK_FINANCES','SUPPORT_REIMBURSEMENT_PROMISE_DUE',$caseId);
        }
        if($resolution==='EMAIL_REVIEW'){
            if(in_array((string)($case['state']??''),[SvAmazonReturnStates::EMAIL_REVIEW_SENT,SvAmazonReturnStates::EMAIL_REVIEW_RESPONSE_PENDING],true))return null;
            if($safeTId==='')return $this->decision('BLOCKED_REVIEW','SUPPORT_RESOLUTION_SAFE_T_ID_MISSING',$caseId);
            return [
                'action'=>'SAFE_T_EMAIL_REVIEW','reason'=>'SUPPORT_RESOLUTION_DIRECTS_EMAIL_REVIEW','case_id'=>$caseId,
                'idempotency_key'=>hash('sha256','support-email-review|'.$safeTId.'|'.$support['case_id'].'|'.$support['content_fingerprint']),
            ];
        }
        if($resolution==='SAFE_T_APPEAL'){
            if(in_array((string)($case['state']??''),[
                SvAmazonReturnStates::APPEAL_SUBMITTED,SvAmazonReturnStates::APPEAL_APPROVED,SvAmazonReturnStates::APPEAL_DENIED_FINAL,
                SvAmazonReturnStates::EMAIL_REVIEW_SENT,SvAmazonReturnStates::EMAIL_REVIEW_RESPONSE_PENDING,
            ],true))return null;
            $deadline=SvAmazonRequestedWait::timestamp($case['appeal_deadline_at']??null);
            if($safeTId!=='' && $deadline instanceof DateTimeImmutable && $now<=$deadline && (string)($case['state']??'')!==SvAmazonReturnStates::APPEAL_SUBMITTED){
                return [
                    'action'=>'SAFE_T_APPEAL','reason'=>'SUPPORT_RESOLUTION_DIRECTS_SAFE_T_APPEAL','case_id'=>$caseId,
                    'idempotency_key'=>hash('sha256','support-safe-t-appeal|'.$safeTId.'|'.$support['case_id'].'|'.$support['content_fingerprint']),
                ];
            }
            return $this->decision('BLOCKED_REVIEW','SUPPORT_RESOLUTION_APPEAL_WINDOW_UNAVAILABLE',$caseId);
        }
        return $this->decision('BLOCKED_REVIEW','SELLER_SUPPORT_RESOLUTION_AMBIGUOUS',$caseId);
    }

    private function hasActiveSupportCase(array $case,array $timeline=[]): bool
    {
        $id=trim((string)($case['support_case_id'] ?? ''));
        if($id==='')return false;
        $event=$this->latestSupportObservation($case,$timeline);
        if(is_array($event)){
            $payload=is_array($event['payload']??null)?$event['payload']:[];
            try{return !SvAmazonSellerSupportStatus::isTerminalStatus(SvAmazonSellerSupportStatus::normalize($payload)['case_status']);}catch(Throwable){}
        }
        $status=strtoupper(trim((string)($case['support_case_status'] ?? 'OPEN')));
        return !SvAmazonSellerSupportStatus::isTerminalStatus($status);
    }
}
