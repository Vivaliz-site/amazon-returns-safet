<?php
declare(strict_types=1);
require_once __DIR__.'/Enums.php';
require_once __DIR__.'/TenantPersistence.php';
final class SvAmazonAdminCaseOverride
{
    public const ACTIONS=['CLOSE_LOSS','REOPEN','MARK_RECOVERED','REQUEST_REVIEW','PREPARE_CONTESTATION','SET_STATE','UPDATE_FIELDS'];
    // reconciled_credit_amount is deliberately excluded: an admin decision cannot create financial credit.
    private const FIELDS=['quantity_ordered','quantity_refunded','quantity_received','program','refund_initiator','refund_at','seller_debit_at','refund_amount','expected_reimbursement_amount','physical_status','state','policy_version_id','eligibility_at','next_action_at','safe_t_id','support_case_id','repeated_denial_count','last_denial_fingerprint','appeal_deadline_at','terminal_reason','closed_at'];
    public static function apply(SvAmazonTenantPersistence $p,int $caseId,string $action,array $fields,string $reason,string $actor,string $requestId,string $expectedUpdatedAt):array
    {
        if($caseId<1)throw new InvalidArgumentException('Caso inválido.');
        $action=strtoupper(trim($action));if(!in_array($action,self::ACTIONS,true))throw new InvalidArgumentException('Ação administrativa inválida.');
        $reason=trim($reason);if($reason===''||strlen($reason)>2000)throw new InvalidArgumentException('Justificativa obrigatória (até 2.000 caracteres).');
        $actor=trim($actor);if($actor===''||strlen($actor)>191)throw new InvalidArgumentException('Identidade administrativa inválida.');
        $requestId=trim($requestId);if(preg_match('/^[A-Za-z0-9._:-]{8,128}$/',$requestId)!==1)throw new InvalidArgumentException('Identificador de requisição inválido.');
        $expectedUpdatedAt=trim($expectedUpdatedAt);if($expectedUpdatedAt===''||strlen($expectedUpdatedAt)>32)throw new InvalidArgumentException('Versão do caso inválida.');
        $ctx=$p->context();$db=$p->db();$key=hash('sha256',$ctx->scopeKey().'|ADMIN_CASE_OVERRIDE|'.$caseId.'|'.$requestId);
        $existing=$p->events->findIdByIdempotencyKey($key);
        if($existing!==null){$case=$p->cases->find($caseId);if(!$case)throw new RuntimeException('CASE_NOT_FOUND');return ['case'=>$case,'override_id'=>null,'event_id'=>$existing,'idempotent_replay'=>true];}
        $before=$p->cases->find($caseId);if(!$before)throw new RuntimeException('CASE_NOT_FOUND');
        if((string)($before['updated_at']??'')!==$expectedUpdatedAt)throw new RuntimeException('STALE_CASE_VERSION');
        $requestedState=$action==='MARK_RECOVERED'?'RECOVERED':strtoupper(trim((string)($fields['state']??'')));
        $claimOnly=in_array($action,['MARK_RECOVERED','SET_STATE'],true)
            &&$requestedState===SvAmazonReturnStates::RECOVERED
            &&!self::hasReconciledRecovery($before);
        if($action==='UPDATE_FIELDS'&&$requestedState===SvAmazonReturnStates::RECOVERED&&!self::hasReconciledRecovery($before)){
            throw new InvalidArgumentException('RECOVERED exige crédito financeiro conciliado. Use Marcar como recuperado para registrar apenas a indicação.');
        }
        $patch=$claimOnly?[]:self::patch($action,$fields);
        $db->beginTransaction();
        try{
            // An unconfirmed recovery is audit-only. Do not close the case, stop
            // scheduling or overwrite its financial/operational state.
            if(!$claimOnly)$p->cases->adminOverrideUpdate($caseId,$patch,$expectedUpdatedAt);
            $after=$p->cases->find($caseId);if(!$after)throw new RuntimeException('CASE_NOT_FOUND');
            if((string)($after['updated_at']??'')!==$expectedUpdatedAt&&$claimOnly)throw new RuntimeException('STALE_CASE_VERSION');
            $actorId=(int)sprintf('%u',crc32($actor));if($actorId<1)$actorId=1;
            $beforeSnap=self::snapshot($before,array_keys($patch));$afterSnap=self::snapshot($after,array_keys($patch));
            if($claimOnly){
                $beforeSnap['admin_recovery_claim']=null;
                $afterSnap['admin_recovery_claim']='PENDING_FINANCIAL_RECONCILIATION';
            }
            $overrideId=$p->cases->recordAdminOverride($caseId,$actorId,$reason,$beforeSnap,$afterSnap);$payload=['action'=>$action,'actor'=>$actor,'actor_id'=>$actorId,'reason'=>$reason,'request_id'=>$requestId,'override_id'=>$overrideId,'before'=>$beforeSnap,'after'=>$afterSnap,'recovery_claim_pending'=>$claimOnly,'external_operation'=>'NOT_REQUESTED','financial_credit_changed'=>false];
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $eventId=$p->events->append(['case_id'=>$caseId,'event_type'=>'ADMIN_CASE_OVERRIDE','source'=>'ADMIN_UI','source_event_id'=>$requestId,'idempotency_key'=>$key,'occurred_at'=>gmdate('Y-m-d H:i:s'),'payload'=>$payload,'evidence_sha256'=>hash('sha256',$json)]);
            $db->commit();return ['case'=>$after,'override_id'=>$overrideId,'event_id'=>$eventId,'idempotent_replay'=>false,'recovery_claim_pending'=>$claimOnly];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e->getMessage()==='STALE_CASE_VERSION'){
                $replayed=$p->events->findIdByIdempotencyKey($key);
                if($replayed!==null){$current=$p->cases->find($caseId);if($current)return ['case'=>$current,'override_id'=>null,'event_id'=>$replayed,'idempotent_replay'=>true];}
            }
            throw $e;
        }
    }
    /** RECOVERED is a financial fact, never just an administrative opinion. */
    private static function hasReconciledRecovery(array $case):bool
    {
        $expected=(float)($case['expected_reimbursement_amount']??0);
        $credited=(float)($case['reconciled_credit_amount']??0);
        return $expected>0 && $credited>0 && round(($credited-$expected)*100)>=0;
    }
    private static function patch(string $action,array $fields):array
    {
        $now=gmdate('Y-m-d H:i:s');
        if($action==='CLOSE_LOSS')return ['state'=>SvAmazonReturnStates::CLOSED_LOSS,'terminal_reason'=>'ADMIN_MANUAL_CLOSED_LOSS','closed_at'=>$now,'next_action_at'=>null];
        if($action==='REOPEN')return ['state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'terminal_reason'=>null,'closed_at'=>null,'next_action_at'=>null];
        if($action==='REQUEST_REVIEW')return ['state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'terminal_reason'=>'ADMIN_MANUAL_REVIEW_REQUESTED','closed_at'=>null,'next_action_at'=>null];
        if($action==='PREPARE_CONTESTATION')return ['state'=>SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,'terminal_reason'=>'ADMIN_MANUAL_CONTESTATION_PREPARED','closed_at'=>null,'next_action_at'=>null];
        if($action==='MARK_RECOVERED')return ['state'=>SvAmazonReturnStates::RECOVERED,'terminal_reason'=>'ADMIN_MANUAL_RECOVERY_CLASSIFICATION','closed_at'=>$now,'next_action_at'=>null];
        if($action==='SET_STATE'){$state=strtoupper(trim((string)($fields['state']??'')));SvAmazonReturnStates::assertValid($state);return ['state'=>$state,'terminal_reason'=>in_array($state,SvAmazonReturnStates::terminal(),true)?'ADMIN_MANUAL_STATE_OVERRIDE':null,'closed_at'=>in_array($state,SvAmazonReturnStates::terminal(),true)?$now:null,'next_action_at'=>null];}
        if($action!=='UPDATE_FIELDS'||$fields===[])throw new InvalidArgumentException('Informe os campos que deseja alterar.');
        foreach(array_keys($fields) as $field)if(!in_array($field,self::FIELDS,true))throw new InvalidArgumentException('Campo não editável: '.$field);
        if(isset($fields['state'])){
            $state=strtoupper(trim((string)$fields['state']));SvAmazonReturnStates::assertValid($state);$fields['state']=$state;
            if(in_array($state,SvAmazonReturnStates::terminal(),true)){
                $fields['closed_at']=$fields['closed_at']??$now;
                $fields['terminal_reason']=$fields['terminal_reason']??($state===SvAmazonReturnStates::RECOVERED?'ADMIN_MANUAL_RECOVERY_CLASSIFICATION':'ADMIN_MANUAL_CLOSED_LOSS');
                $fields['next_action_at']=$fields['next_action_at']??null;
            }else{
                $fields['closed_at']=$fields['closed_at']??null;
                $fields['terminal_reason']=$fields['terminal_reason']??null;
            }
        }
        foreach(['program'=>SvAmazonReturnPrograms::class,'physical_status'=>SvAmazonReturnPhysicalStatuses::class,'refund_initiator'=>SvAmazonRefundInitiators::class] as $field=>$enum){
            if(!isset($fields[$field]))continue;
            $fields[$field]=strtoupper(trim((string)$fields[$field]));
            if(!in_array($fields[$field],$enum::all(),true))throw new InvalidArgumentException('Valor de domínio inválido: '.$field);
        }
        return $fields;
    }
    private static function snapshot(array $case,array $fields):array{$out=['id'=>(int)($case['id']??0),'updated_at'=>$case['updated_at']??null];foreach($fields as $field)$out[$field]=$case[$field]??null;return $out;}
}
