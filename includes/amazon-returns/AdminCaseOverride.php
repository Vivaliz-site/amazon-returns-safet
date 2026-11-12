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
        $patch=self::patch($action,$fields);$before=$p->cases->find($caseId);if(!$before)throw new RuntimeException('CASE_NOT_FOUND');
        if((string)($before['updated_at']??'')!==$expectedUpdatedAt)throw new RuntimeException('STALE_CASE_VERSION');
        $db->beginTransaction();
        try{
            $p->cases->adminOverrideUpdate($caseId,$patch,$expectedUpdatedAt);
            $after=$p->cases->find($caseId);if(!$after)throw new RuntimeException('CASE_NOT_FOUND');
            $actorId=(int)sprintf('%u',crc32($actor));if($actorId<1)$actorId=1;
            $beforeSnap=self::snapshot($before,array_keys($patch));$afterSnap=self::snapshot($after,array_keys($patch));
            $overrideId=$p->cases->recordAdminOverride($caseId,$actorId,$reason,$beforeSnap,$afterSnap);$payload=['action'=>$action,'actor'=>$actor,'actor_id'=>$actorId,'reason'=>$reason,'request_id'=>$requestId,'override_id'=>$overrideId,'before'=>$beforeSnap,'after'=>$afterSnap,'external_operation'=>'NOT_REQUESTED','financial_credit_changed'=>false];
            $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $eventId=$p->events->append(['case_id'=>$caseId,'event_type'=>'ADMIN_CASE_OVERRIDE','source'=>'ADMIN_UI','source_event_id'=>$requestId,'idempotency_key'=>$key,'occurred_at'=>gmdate('Y-m-d H:i:s'),'payload'=>$payload,'evidence_sha256'=>hash('sha256',$json)]);
            $db->commit();return ['case'=>$after,'override_id'=>$overrideId,'event_id'=>$eventId,'idempotent_replay'=>false];
        }catch(Throwable $e){
            if($db->inTransaction())$db->rollBack();
            if($e->getMessage()==='STALE_CASE_VERSION'){
                $replayed=$p->events->findIdByIdempotencyKey($key);
                if($replayed!==null){$current=$p->cases->find($caseId);if($current)return ['case'=>$current,'override_id'=>null,'event_id'=>$replayed,'idempotent_replay'=>true];}
            }
            throw $e;
        }
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
        if(isset($fields['program'])&&!in_array(strtoupper((string)$fields['program']),SvAmazonReturnPrograms::all(),true))throw new InvalidArgumentException('Tipo de logística inválido.');
        if(isset($fields['physical_status'])&&!in_array(strtoupper((string)$fields['physical_status']),SvAmazonReturnPhysicalStatuses::all(),true))throw new InvalidArgumentException('Situação física inválida.');
        if(isset($fields['refund_initiator'])&&!in_array(strtoupper((string)$fields['refund_initiator']),SvAmazonRefundInitiators::all(),true))throw new InvalidArgumentException('Origem do reembolso inválida.');
        return $fields;
    }
    private static function snapshot(array $case,array $fields):array{$out=['id'=>(int)($case['id']??0),'updated_at'=>$case['updated_at']??null];foreach($fields as $field)$out[$field]=$case[$field]??null;return $out;}
}
