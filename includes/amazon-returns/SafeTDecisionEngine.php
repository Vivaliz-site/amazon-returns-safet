<?php
declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/DenialAnalyzer.php';
require_once __DIR__ . '/SafeTStatus.php';

final class SvAmazonSafeTDecisionEngine
{
    public function __construct(private ?SvAmazonDenialAnalyzer $denialAnalyzer = null)
    {
        $this->denialAnalyzer ??= new SvAmazonDenialAnalyzer();
    }

    /** @return array<string,mixed> */
    public function nextAction(array $case,array $timeline,array $policy): array
    {
        $caseId=(int)($case['id'] ?? 0);
        $orderId=trim((string)($case['amazon_order_id'] ?? ''));
        $safeTId=trim((string)($case['safe_t_id'] ?? ''));
        $state=trim((string)($case['state'] ?? ''));

        if($this->hasRecoveredCredit($case))return $this->decision('WAIT','ALREADY_REIMBURSED',$caseId);
        if((string)($case['physical_status'] ?? '')===SvAmazonReturnPhysicalStatuses::RECEIVED_OK){
            return $this->decision('WAIT','PHYSICAL_RETURN_RECEIVED',$caseId);
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
                if($this->hasActiveSupportCase($case)){
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

        $initiator=(string)($case['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN);
        if(!SvAmazonRefundInitiators::isValid($initiator) || $initiator===SvAmazonRefundInitiators::UNKNOWN){
            return $this->decision('BLOCKED_REVIEW','REFUND_INITIATOR_UNKNOWN',$caseId);
        }
        if(trim((string)($case['seller_debit_at'] ?? ''))==='')return $this->decision('WAIT','SELLER_DEBIT_NOT_CONFIRMED',$caseId);
        if(($policy['state'] ?? null)===SvAmazonReturnStates::POLICY_REVIEW_REQUIRED)return $this->decision('BLOCKED_REVIEW','POLICY_REVIEW_REQUIRED',$caseId);
        if(($policy['eligible'] ?? false)!==true)return $this->decision('WAIT','NOT_YET_ELIGIBLE',$caseId);

        $policyId=(string)($policy['policy_version_id'] ?? 'unknown');
        $eligibilityAt=(string)($policy['eligibility_at'] ?? 'unknown');
        return [
            'action'=>'SAFE_T_SUBMIT',
            'reason'=>'FIRST_ELIGIBLE_ATTEMPT',
            'idempotency_key'=>hash('sha256','safe-t-submit|'.$caseId.'|'.$orderId.'|'.$policyId.'|'.$eligibilityAt),
        ];
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
                if($this->hasActiveSupportCase($case))return $this->decision('WAIT','SUPPORT_ESCALATION_ALREADY_ACTIVE',$caseId);
                return [
                    'action'=>'SELLER_SUPPORT_OPEN',
                    'reason'=>'EMAIL_REVIEW_ANALYZER_SELECTED_SUPPORT',
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

    private function hasRecoveredCredit(array $case): bool
    {
        $expected=(float)($case['expected_reimbursement_amount'] ?? 0);
        $credited=(float)($case['reconciled_credit_amount'] ?? 0);
        return $expected>0.0 && $credited+0.00001 >= $expected;
    }

    private function hasActiveSupportCase(array $case): bool
    {
        $id=trim((string)($case['support_case_id'] ?? ''));
        if($id==='')return false;
        $status=strtoupper(trim((string)($case['support_case_status'] ?? 'OPEN')));
        return !in_array($status,['CLOSED','RESOLVED','CANCELLED'],true);
    }
}
