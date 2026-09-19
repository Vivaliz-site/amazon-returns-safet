<?php
declare(strict_types=1);

require_once __DIR__.'/SafeTEmailReview.php';

final class SvAmazonExternalWritePayload
{
    /** @return array{write_snapshot:array<string,mixed>} */
    public static function build(array $decision,array $case,array $timeline):array
    {
        $action=strtoupper(trim((string)($decision['action']??'')));
        return ['write_snapshot'=>match($action){
            'SAFE_T_SUBMIT'=>self::sellerCentral($action,$decision,$case,1000),
            'SAFE_T_APPEAL'=>self::sellerCentral($action,$decision,$case,1500),
            'SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'=>self::sellerCentral($action,$decision,$case,9000),
            'SAFE_T_EMAIL_REVIEW'=>self::gmailReview($case,$timeline),
            'SAFE_T_EMAIL_REPLY'=>self::gmailReply($decision,$case,$timeline),
            default=>throw new InvalidArgumentException('Unsupported write snapshot action.'),
        }];
    }

    /** @return array<string,mixed> */
    private static function sellerCentral(string $action,array $decision,array $case,int $max):array
    {
        $text=self::sellerCentralNarrative($action,$decision,$case);
        $text=self::limit($text,$max);
        if(trim($text)==='')throw new LogicException('Seller Central write snapshot narrative is empty.');
        return self::snapshot('seller_central_bridge','narrative',$text);
    }
    private static function sellerCentralNarrative(string $action,array $decision,array $case):string
    {
        $order=trim((string)($case['amazon_order_id']??''));
        $safeT=trim((string)($case['safe_t_id']??''));
        $reason=trim((string)($decision['reason']??''));
        if($action==='SAFE_T_SUBMIT' && $reason==='SUPPORT_RESOLUTION_DIRECTS_SAFE_T_SUBMISSION'){
            $supportId=trim((string)($decision['support_case_id']??''));
            return 'Pedido '.$order.'. No chamado de Suporte ao Vendedor '.$supportId.', a Amazon nos orientou expressamente a registrar uma nova reivindicação SAFE-T para este pedido, na categoria "Perdido em trânsito/Itens ausentes". '
                .'Temos ciência de que o comprador foi reembolsado; porém, o produto não retornou ao nosso estoque e ainda não identificamos em nossa conta de vendedor o ressarcimento correspondente. '
                .'Estamos registrando esta SAFE-T conforme a orientação recebida e solicitamos o nosso ressarcimento como vendedores.';
        }
        if($action==='SAFE_T_SUBMIT' && $reason==='DELIVERED_CUSTOMER_REFUNDED_UNPAID'){
            $tracking=[];
            foreach(is_array($case['customer_tracking_ids']??null)?$case['customer_tracking_ids']:[] as $value){
                $value=trim((string)$value);if($value!=='')$tracking[]=$value;
            }
            $trackingText=$tracking!==[]?' Rastreio: '.implode(', ',array_values(array_unique($tracking))).'.':'';
            return 'Pedido '.$order.'. A entrega ao cliente foi confirmada pela Amazon.'.$trackingText.' '
                .'O comprador recebeu reembolso e a conciliação financeira mais recente confirma que o vendedor ainda não recebeu '
                .'o ressarcimento correspondente. Solicito o ressarcimento devido ao vendedor.';
        }
        if($action==='SELLER_SUPPORT_UPDATE'
            && $reason==='SUPPORT_BUYER_REFUND_IS_NOT_SELLER_REIMBURSEMENT'){
            $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
            $credited=max(0.0,(float)($case['reconciled_credit_amount']??0));
            $outstanding=max(0.0,$expected-$credited);
            return 'Temos ciência de que o comprador já foi reembolsado no pedido '.$order.'. '
                .'Nossa solicitação não se refere ao reembolso realizado ao comprador. '
                .'Estamos solicitando o nosso ressarcimento como vendedores. '
                .'Até o momento, não identificamos em nossa conta de vendedor o crédito de '.self::brl($outstanding).' correspondente a esse ressarcimento. '
                .'O reembolso ao comprador confirma apenas o estorno ao cliente e não comprova que recebemos o ressarcimento devido. '
                .'Caso a Amazon considere que esse ressarcimento já foi efetuado, solicitamos que informe o valor creditado em nossa conta de vendedor, '
                .'a data do crédito, o ID da transação financeira e/ou o ID do ressarcimento, além do relatório ou evento financeiro em que esse crédito aparece. '
                .'Enquanto não houver a identificação e conciliação desse crédito em nossa conta de vendedor, consideramos o ressarcimento pendente.';
        }
        if(in_array($action,['SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)
            && $reason==='CLASSIC_FBA_UNPAID_AFTER_FINANCE_RECONCILIATION'){
            $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
            $credited=max(0.0,(float)($case['reconciled_credit_amount']??0));
            $outstanding=max(0.0,$expected-$credited);
            return 'Pedido '.$order.'. Rota de ressarcimento FBA. A conciliação financeira mais recente confirma saldo do vendedor ainda não ressarcido. '
                .'Valor esperado: '.self::brl($expected).'. Crédito efetivamente conciliado: '.self::brl($credited).'. '
                .'Saldo pendente: '.self::brl($outstanding).'. Solicito revisão manual do ressarcimento FBA e pagamento do saldo devido ao vendedor.';
        }
        if(in_array($action,['SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'],true)){
            return 'SAFE-T '.$safeT.', pedido '.$order.'. A devolução permanece não recebida fisicamente pelo vendedor. '
                .'A nova negativa repetiu a justificativa sem responder aos fatos e às evidências apresentados. '
                .'Solicito revisão manual por equipe especializada. Se a Amazon considera que houve devolução, '
                .'favor informar data, transportadora, rastreio, endereço de entrega e comprovante de entrega. '.$reason;
        }
        if($action==='SAFE_T_APPEAL'){
            return 'Pedido '.$order.', SAFE-T '.$safeT.'. O produto não foi recebido fisicamente pelo vendedor. '
                .'Solicito reavaliação da decisão com análise do fluxo de devolução, rastreio e eventual comprovante de entrega. '.$reason;
        }
        return 'Pedido '.$order.'. A Amazon efetuou o reembolso ao comprador e a devolução não foi recebida pelo vendedor. '
            .'Solicito o ressarcimento correspondente, com validação do fluxo de devolução e do débito ao vendedor.';
    }

    /** @return array<string,mixed> */
    private static function gmailReview(array $case,array $timeline):array
    {
        $message=SvAmazonSafeTEmailReview::compose($case,$timeline);
        return self::messageSnapshot($message);
    }
    /** @return array<string,mixed> */
    private static function gmailReply(array $decision,array $case,array $timeline):array
    {
        $resumeScope=trim((string)($decision['resume_scope']??''));
        if($resumeScope==='')$resumeScope=null;
        $message=SvAmazonSafeTEmailReview::composeReply($case,$timeline,null,$resumeScope);
        return self::messageSnapshot($message);
    }

    /** @param array<string,mixed> $message @return array<string,mixed> */
    private static function messageSnapshot(array $message):array
    {
        $body=(string)($message['body']??'');
        if(trim($body)==='')throw new LogicException('Gmail write snapshot body is empty.');
        return [
            'format_version'=>2,
            'channel'=>'gmail',
            'message'=>$message,
            'content_sha256'=>hash('sha256',$body),
        ];
    }

    /** @return array<string,mixed> */
    private static function snapshot(string $channel,string $field,string $text):array
    {
        return ['format_version'=>2,'channel'=>$channel,$field=>$text,'content_sha256'=>hash('sha256',$text)];
    }

    private static function brl(float $value):string
    {
        return 'R$ '.number_format($value,2,',','.');
    }

    private static function limit(string $text,int $max):string
    {
        return function_exists('mb_substr')?mb_substr($text,0,$max,'UTF-8'):substr($text,0,$max);
    }
}
