<?php

declare(strict_types=1);

require_once __DIR__ . '/SafeTStatus.php';
require_once __DIR__ . '/SafeTDecisionEngine.php';

final class SvAmazonSafeTEmailReview
{
    public const RECIPIENT = 'Safe-T-Review@amazon.com';

    /** @return array{to:string,subject:string,body:string} */
    public static function compose(array $case, array $timeline): array
    {
        $safeTId = trim((string)($case['safe_t_id'] ?? ''));
        $orderId = trim((string)($case['amazon_order_id'] ?? ''));
        if ($safeTId === '' || $orderId === '') {
            throw new InvalidArgumentException('SAFE-T email review requires SAFE-T and order IDs.');
        }
        $context = SvAmazonSafeTStatus::denialContext($timeline);
        $denial = trim((string)($case['latest_denial_text'] ?? $context['latest_denial_text']));
        if ($denial === '') throw new InvalidArgumentException('SAFE-T email review requires real denial text.');

        $subject = 'Solicitação de revisão detalhada — SAFE-T ' . $safeTId . ' / Pedido ' . $orderId;
        $lines = [
            'Olá, equipe SAFE-T,',
            '',
            'Solicito uma revisão detalhada e manual da SAFE-T ' . $safeTId . ', referente ao pedido ' . $orderId . '.',
            '',
            'Motivo/decisão mais recente informado pela Amazon:',
            $denial,
            '',
        ];
        $facts = [];
        if (($case['refund_at'] ?? null) !== null && trim((string)$case['refund_at']) !== '') $facts[] = 'Data do reembolso: ' . trim((string)$case['refund_at']);
        if (($case['seller_debit_at'] ?? null) !== null && trim((string)$case['seller_debit_at']) !== '') $facts[] = 'Data do débito/exposição do vendedor: ' . trim((string)$case['seller_debit_at']);
        if (isset($case['refund_amount']) && (float)$case['refund_amount'] > 0) $facts[] = 'Valor do reembolso/débito registrado: R$ ' . number_format((float)$case['refund_amount'], 2, ',', '.');
        if (trim((string)($case['physical_status'] ?? '')) === 'NOT_RECEIVED') $facts[] = 'Situação física registrada: produto não recebido pelo vendedor.';
        if ($facts !== []) {
            $lines[] = 'Fatos registrados no caso:';
            foreach ($facts as $fact) $lines[] = '- ' . $fact;
            $lines[] = '';
        }
        $supportCase = trim((string)($case['support_case_id'] ?? ''));
        if ($supportCase !== '') {
            $lines[] = 'Caso relacionado no Suporte ao Vendedor: ' . $supportCase . '.';
            $lines[] = '';
        }
        if(($case['state']??'')==='APPEAL_DENIED_FINAL')$lines[]='O recurso no fluxo SAFE-T foi analisado e negado, conforme o estado registrado do caso.';
        $lines[]='Solicito nova revisao manual do historico do pedido, da devolucao e do debito, com ressarcimento quando devido.';
        $lines[] = 'Caso a Amazon considere que o item foi devolvido/entregue ao vendedor, solicito informar a data, rastreamento, transportadora e comprovante de entrega utilizados nessa conclusão.';
        $lines[] = '';
        $lines[] = 'Atenciosamente,';
        $lines[] = 'ShopVivaLiz';

        return ['to'=>self::RECIPIENT,'subject'=>$subject,'body'=>implode("\n", $lines)];
    }

    /** @return array{to:string,subject:string,body:string,thread_id:string,in_reply_to:string} */
    public static function composeReply(array $case,array $timeline,?DateTimeImmutable $now=null,?string $reservedResumeScope=null): array
    {
        $safeTId=trim((string)($case['safe_t_id'] ?? ''));
        $orderId=trim((string)($case['amazon_order_id'] ?? ''));
        if($safeTId==='' || $orderId==='')throw new InvalidArgumentException('SAFE-T email reply requires SAFE-T and order IDs.');
        $response=null;
        for($i=count($timeline)-1;$i>=0;$i--){
            $event=$timeline[$i] ?? null;
            if(is_array($event) && ($event['event_type'] ?? '')==='SAFE_T_EMAIL_REVIEW_RESPONSE'){$response=$event;break;}
        }
        $payload=is_array($response['payload'] ?? null)?$response['payload']:[];
        $datedDecision=null;
        if($reservedResumeScope!==null || strtoupper(trim((string)($payload['review_suggested_action'] ?? '')))!=='RESPOND_EMAIL'){
            $datedDecision=(new SvAmazonSafeTDecisionEngine())->nextAction($case,$timeline,[],$now);
            if(($datedDecision['action']??'')!=='SAFE_T_EMAIL_REPLY' || $reservedResumeScope===null || ($datedDecision['resume_scope']??null)!==$reservedResumeScope){
                throw new LogicException('Email review response is not approved for automatic reply.');
            }
        }
        $threadId=trim((string)($payload['gmail_thread_id'] ?? ''));
        $inReplyTo=trim((string)($payload['gmail_rfc_message_id'] ?? ''));
        if($threadId==='')throw new LogicException('Email review reply requires Gmail thread correlation.');
        $subject='Re: Solicitação de revisão detalhada — SAFE-T '.$safeTId.' / Pedido '.$orderId;
        $lines=['Olá, equipe SAFE-T,','','Em resposta à análise da SAFE-T '.$safeTId.' do pedido '.$orderId.', seguem somente os fatos verificados atualmente disponíveis:'];
        if($datedDecision!==null)$lines[]='- Retomada na data solicitada pela Amazon, apos nova verificacao financeira sem ressarcimento integral: '.(string)$datedDecision['next_action_at'].' UTC.';
        if(trim((string)($case['physical_status'] ?? ''))==='NOT_RECEIVED')$lines[]='- O produto permanece registrado como não recebido fisicamente pelo vendedor.';
        if(trim((string)($case['seller_debit_at'] ?? ''))!=='')$lines[]='- Débito/exposição do vendedor: '.trim((string)$case['seller_debit_at']).'.';
        if((float)($case['expected_reimbursement_amount'] ?? 0)>0)$lines[]='- Valor econômico esperado para conciliação: R$ '.number_format((float)$case['expected_reimbursement_amount'],2,',','.').'.';
        $excerpt=trim((string)($payload['review_excerpt'] ?? ''));
        if($excerpt!==''){
            $lines[]='';
            $lines[]='Referência da mensagem recebida da Amazon:';
            $lines[]=function_exists('mb_substr')?mb_substr($excerpt,0,500,'UTF-8'):substr($excerpt,0,500);
        }
        $lines[]='';
        $lines[]='Solicitamos que a revisão considere esses fatos e informe objetivamente a conclusão e, se aplicável, o próximo passo ou documento específico necessário.';
        $lines[]='';
        $lines[]='Atenciosamente,';
        $lines[]='ShopVivaLiz';
        return [
            'to'=>self::RECIPIENT,
            'subject'=>$subject,
            'body'=>implode("\n",$lines),
            'thread_id'=>$threadId,
            'in_reply_to'=>$inReplyTo,
        ];
    }
}
