<?php
declare(strict_types=1);

final class SvAmazonCockpitCaseSummary
{
    private const WRITE_ACTIONS=['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'];
    private const TERMINAL_STATES=['RECOVERED','CLOSED_LOSS','RECEIVED_OK'];

    /** @return array<string,mixed> */
    public static function build(array $case,?array $review,DateTimeImmutable $now):array
    {
        $action=strtoupper(trim((string)($case['current_action']??'WAIT')));
        $state=strtoupper(trim((string)($case['state']??'')));
        $responsibility=self::responsibility($state,$action,$review);
        $due=self::dueDate($case,$action);
        $overdue=$responsibility==='SYSTEM'&&in_array($action,self::WRITE_ACTIONS,true)&&self::isPast($due,$now)&&!self::writeAlreadyHandled($case,$action);
        $lastRead=self::date($case['last_read_back']['occurred_at']??null);
        $stale=$lastRead!==null&&($now->getTimestamp()-$lastRead->getTimestamp())>36*3600;
        return [
            'responsibility'=>$responsibility,
            'headline'=>self::headline($case,$responsibility),
            'what_happened'=>self::whatHappened($case),
            'last_action'=>self::lastAction($case),
            'next_step'=>self::nextStep($case,$responsibility),
            'decision_explanation'=>self::decisionExplanation($case,$responsibility),
            'decision_basis'=>self::decisionBasis($case),
            'overdue'=>$overdue,'stale'=>$stale,'due_at'=>$due,
            'outstanding_amount'=>(float)($case['outstanding_amount']??0),
            'last_read_at'=>$lastRead?->format('Y-m-d H:i:s'),
        ];
    }

    private static function responsibility(string $state,string $action,?array $review):string
    {
        if(strtoupper(trim((string)($review['status']??'')))==='OPEN'||in_array($action,['HUMAN_REVIEW','BLOCKED_REVIEW'],true))return 'USER';
        return in_array($state,self::TERMINAL_STATES,true)?'COMPLETE':'SYSTEM';
    }

    private static function headline(array $case,string $responsibility):string
    {
        if($responsibility==='USER')return 'Este caso precisa da sua decisão';
        return match(strtoupper(trim((string)($case['state']??'')))){
            'RECOVERED'=>'Valor recuperado','CLOSED_LOSS'=>'Caso encerrado como perda','RECEIVED_OK'=>'Devolução recebida sem divergência',
            'CREDIT_PENDING','SAFE_T_APPROVED','APPEAL_APPROVED'=>'Aguardando crédito da Amazon',
            'SAFE_T_SUBMITTED','APPEAL_SUBMITTED','EMAIL_REVIEW_SENT','EMAIL_REVIEW_RESPONSE_PENDING','SUPPORT_ESCALATION'=>'Aguardando resposta da Amazon',
            default=>'Caso em acompanhamento',
        };
    }

    private static function whatHappened(array $case):string
    {
        $parts=[];$refund=(float)($case['refund_amount']??0);
        if($refund>0)$parts[]='O cliente recebeu um reembolso de R$ '.number_format($refund,2,',','.').'.';
        if(!empty($case['customer_delivery_confirmed']))$parts[]='O rastreio do pedido original informa entrega ao cliente.';
        if(($case['physical_status']??null)==='NOT_RECEIVED')$parts[]='A devolução física ainda não foi confirmada como recebida pela loja.';
        if(($case['physical_status']??null)==='RECEIVED_DISCREPANT')$parts[]='A devolução chegou com divergência.';
        return $parts!==[]?implode(' ',$parts):'O sistema está acompanhando os dados do pedido, reembolso, devolução e financeiro.';
    }

    private static function lastAction(array $case):string
    {
        $write=is_array($case['last_external_write']??null)?$case['last_external_write']:null;
        if($write){$status=strtoupper(trim((string)($write['status']??'')));return match($status){
            'SUCCEEDED','SUCCESS'=>'A última ação automática foi concluída e registrada.',
            'PENDING','READY'=>'A próxima ação já está programada.',
            'PROCESSING'=>'Uma ação automática está em execução.',
            'FAILED','DEAD_LETTER'=>'A última tentativa automática falhou e precisa ser retomada.',
            default=>'A última ação externa foi registrada pelo sistema.',
        };}
        if(!empty($case['last_read_back']['occurred_at']))return 'O sistema consultou recentemente as informações externas deste caso.';
        return 'O caso está registrado e segue em acompanhamento.';
    }

    private static function nextStep(array $case,string $responsibility):string
    {
        if($responsibility==='USER')return 'Revise somente a dúvida indicada antes de confirmar uma decisão.';
        if($responsibility==='COMPLETE')return 'Nenhuma providência adicional está prevista.';
        return match(strtoupper(trim((string)($case['current_action']??'WAIT')))){
            'SAFE_T_SUBMIT'=>'O sistema solicitará o ressarcimento SAFE-T automaticamente.',
            'SAFE_T_APPEAL'=>'O sistema enviará o recurso SAFE-T automaticamente.',
            'SAFE_T_EMAIL_REVIEW'=>'O sistema pedirá uma nova análise à Amazon por e-mail.',
            'SAFE_T_EMAIL_REPLY'=>'O sistema responderá à Amazon no e-mail já existente.',
            'SELLER_SUPPORT_OPEN'=>'O sistema abrirá um atendimento com o Suporte da Amazon.',
            'SELLER_SUPPORT_UPDATE'=>'O sistema atualizará o atendimento existente com a Amazon.',
            'CHECK_FINANCES'=>'O sistema verificará se o crédito entrou no financeiro.',
            default=>'O sistema continuará acompanhando e retomará o caso quando houver nova condição ou data conhecida.',
        };
    }

    private static function decisionExplanation(array $case,string $responsibility):string
    {
        if($responsibility==='USER')return 'O sistema encontrou uma dúvida material que impede uma ação segura sem sua confirmação.';
        if($responsibility==='COMPLETE')return 'O caso atingiu um estado final e não há nova ação prevista.';
        return match(strtoupper(trim((string)($case['current_action']??'WAIT')))){
            'SAFE_T_SUBMIT'=>'Os fatos disponíveis indicam que o caso pode seguir para solicitação de ressarcimento SAFE-T.',
            'SAFE_T_APPEAL'=>'A situação atual exige recurso e o sistema identificou esse caminho como a próxima ação segura.',
            'SAFE_T_EMAIL_REVIEW'=>'O fluxo SAFE-T já foi usado e a próxima etapa é pedir nova análise à Amazon por e-mail.',
            'SAFE_T_EMAIL_REPLY'=>'A Amazon pediu ou forneceu informação que exige resposta no e-mail existente.',
            'SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'=>'O caso precisa continuar pelo Suporte da Amazon usando o atendimento apropriado.',
            'CHECK_FINANCES'=>'Antes de qualquer nova cobrança, o sistema precisa confirmar se o crédito já entrou.',
            default=>'Os fatos atuais não exigem uma ação externa imediata; o sistema continuará acompanhando o caso.',
        };
    }

    /** @return list<string> */
    private static function decisionBasis(array $case):array
    {
        $items=[];$refund=(float)($case['refund_amount']??0);$credit=(float)($case['reconciled_credit_amount']??0);
        if($refund>0)$items[]='Reembolso ao cliente confirmado: R$ '.number_format($refund,2,',','.').'.';
        if($credit>0)$items[]='Crédito já localizado para a loja: R$ '.number_format($credit,2,',','.').'.';
        if(!empty($case['customer_delivery_confirmed']))$items[]='Rastreio do pedido original confirma entrega ao cliente.';
        if(($case['physical_status']??null)==='NOT_RECEIVED')$items[]='Recebimento físico da devolução ainda não confirmado pela loja.';
        if(($case['physical_status']??null)==='RECEIVED_DISCREPANT')$items[]='A devolução física foi registrada com divergência.';
        if(trim((string)($case['safe_t_id']??''))!=='')$items[]='Existe uma solicitação SAFE-T vinculada ao caso.';
        if(trim((string)($case['support_case_id']??''))!=='')$items[]='Existe atendimento do Suporte da Amazon vinculado ao caso.';
        return $items!==[]?$items:['Pedido, reembolso, devolução e financeiro foram avaliados com os dados disponíveis.'];
    }

    private static function dueDate(array $case,string $action):?string
    {
        $value=match($action){'SAFE_T_APPEAL'=>$case['appeal_deadline_at']??$case['next_action_at']??null,'SAFE_T_SUBMIT'=>$case['eligibility_at']??$case['next_action_at']??null,default=>$case['next_action_at']??null};
        return is_scalar($value)&&trim((string)$value)!==''?trim((string)$value):null;
    }

    private static function isPast(?string $value,DateTimeImmutable $now):bool
    {
        $date=self::date($value);return $date!==null&&$date<$now;
    }

    private static function date(mixed $value):?DateTimeImmutable
    {
        if(!is_scalar($value)||trim((string)$value)==='')return null;
        try{return new DateTimeImmutable((string)$value,new DateTimeZone('UTC'));}catch(Throwable){return null;}
    }

    private static function writeAlreadyHandled(array $case,string $action):bool
    {
        $write=is_array($case['last_external_write']??null)?$case['last_external_write']:[];
        if(strtoupper(trim((string)($write['kind']??'')))!==$action)return false;
        return in_array(strtoupper(trim((string)($write['status']??''))),['PENDING','READY','PROCESSING','SUCCEEDED','SUCCESS'],true);
    }
}
