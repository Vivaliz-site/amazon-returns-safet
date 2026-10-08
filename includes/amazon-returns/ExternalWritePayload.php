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
            && $reason==='SUPPORT_REQUESTED_SELLER_RESPONSE'){
            $latest=mb_strtolower(trim((string)($decision['support_latest_text']??'')),'UTF-8');
            $program=strtoupper(trim((string)($case['program']??'')));
            $asksFulfillmentMode=(str_contains($latest,'seller flex') || str_contains($latest,'fba onsite') || str_contains($latest,'fba clássico') || str_contains($latest,'fba classico'));
            if($asksFulfillmentMode && in_array($program,['FBA','FBA_ONSITE'],true)){
                $mode=$program==='FBA_ONSITE'
                    ? 'FBA Onsite (Seller Flex), em que preparamos e embalamos o pedido em nosso local'
                    : 'FBA Clássico, enviado diretamente pelos centros de distribuição da Amazon';
                $negative=$program==='FBA_ONSITE'?'FBA Clássico':'FBA Onsite/Seller Flex';
                return 'Sobre o pedido '.$order.'. Confirmamos que este produto utiliza '.$mode.'. '
                    .'Não utilizamos '.$negative.' para este item. '
                    .'Por favor, prossigam com a análise e o ressarcimento solicitado neste chamado.';
            }
            $returnQuestionContext=(
                str_contains($latest,'?')
                || str_contains($latest,'confirme')
                || str_contains($latest,'confirmar')
                || str_contains($latest,'poderia')
                || str_contains($latest,'precisamos entender')
                || str_contains($latest,'precisamos confirmar')
            );
            $mentionsReturnOutcome=(
                str_contains($latest,'retornou fisicamente')
                || str_contains($latest,'recebido de volta')
                || str_contains($latest,'recebida de volta')
                || (
                    str_contains($latest,'retorn')
                    && (str_contains($latest,'centro de distribuição') || str_contains($latest,'centro de distribuicao'))
                )
            );
            $asksReturnOutcome=(
                (
                    str_contains($latest,'danific')
                    && (str_contains($latest,'retorn') || str_contains($latest,'devolu'))
                    && (str_contains($latest,'não retorn') || str_contains($latest,'nao retorn') || str_contains($latest,'estoque'))
                )
                || ($returnQuestionContext && $mentionsReturnOutcome)
            );
            $asksNonReceiptEvidence=(
                str_contains($latest,'evidên')
                || str_contains($latest,'eviden')
                || str_contains($latest,'evidence')
            );
            $asksHistoricalAddress=(
                str_contains($latest,'endereço')
                || str_contains($latest,'endereco')
                || str_contains($latest,'address')
            );
            $physicalStatus=strtoupper(trim((string)($case['physical_status']??'')));
            $quantityReceived=(int)($case['quantity_received']??0);
            if($asksNonReceiptEvidence && $asksHistoricalAddress
                && $physicalStatus==='NOT_RECEIVED' && $quantityReceived===0){
                $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
                $credited=max(0.0,(float)($case['reconciled_credit_amount']??0));
                $outstanding=max(0.0,$expected-$credited);
                return 'Sobre o pedido '.$order.', SAFE-T '.$safeT.'. Confirmamos que não recebemos fisicamente a devolução; '
                    .'nosso registro do caso continua indicando que a devolução não foi recebida e a quantidade recebida permanece 0. '
                    .'A conciliação financeira mais recente confirma saldo pendente de '.self::brl($outstanding).' para nós, vendedores. '
                    .'Não temos no registro deste caso o endereço histórico de devolução cadastrado no período; por isso, não podemos afirmar um endereço sem evidência. '
                    .'Solicitamos que a Amazon informe o endereço de devolução efetivamente utilizado, a transportadora, o código de rastreio, '
                    .'a data e hora da entrega, a identificação do recebedor e o comprovante de entrega ao vendedor. '
                    .'Até que exista evidência de entrega física ao vendedor ou conciliação integral do crédito, o ressarcimento permanece pendente para nós.';
            }
            if($asksReturnOutcome && $physicalStatus==='NOT_RECEIVED' && $quantityReceived===0){
                $quantity=max(1,(int)($case['quantity_refunded']??$case['quantity_ordered']??1));
                $returnFact=$quantity===1
                    ? 'Confirmamos que a unidade não retornou ao nosso estoque após a tentativa de devolução. '
                    : 'Confirmamos que as '.$quantity.' unidades não retornaram ao nosso estoque após a tentativa de devolução. ';
                $receiptFact=$quantity===1
                    ? 'A unidade não foi recebida de volta em nosso estoque. '
                    : 'As unidades não foram recebidas de volta em nosso estoque. ';
                return 'Sobre o pedido '.$order.'. '.$returnFact
                    .'Não estamos informando dano após recebimento; '.$receiptFact
                    .'Por favor, prossigam com a investigação e o ressarcimento solicitado neste chamado.';
            }
            $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
            $credited=max(0.0,(float)($case['reconciled_credit_amount']??0));
            $outstanding=max(0.0,$expected-$credited);
            return 'Sobre o pedido '.$order.'. Nós estamos solicitando o nosso ressarcimento como vendedores. '
                .'A conciliação financeira mais recente confirma valor esperado de '.self::brl($expected).', crédito efetivamente conciliado de '.self::brl($credited)
                .' e saldo pendente de '.self::brl($outstanding).'. '
                .'O problema que estamos reportando é o ressarcimento pendente em nossa conta de vendedor; não estamos reportando uma mensagem de erro na interface, portanto não há captura de erro aplicável. '
                .'Solicitamos a reabertura ou continuidade deste chamado e a investigação do ressarcimento. '
                .'Caso a Amazon considere que o crédito já foi efetuado, solicitamos que informe o valor, a data do crédito, o ID da transação e/ou do ressarcimento e o relatório financeiro em que ele aparece.';
        }
        if($action==='SELLER_SUPPORT_UPDATE'
            && $reason==='SUPPORT_TOPIC_MISMATCH_REIMBURSEMENT_RECOVERY'){
            $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
            $credited=max(0.0,(float)($case['reconciled_credit_amount']??0));
            $outstanding=max(0.0,$expected-$credited);
            return 'Sobre o pedido '.$order.'. Nós identificamos que a resposta anterior deste chamado tratou de outro assunto e não resolveu nossa solicitação de ressarcimento. '
                .'Nossa solicitação atual é exclusivamente sobre o saldo do vendedor ainda não ressarcido. '
                .'A conciliação financeira mais recente confirma valor esperado de '.self::brl($expected).', crédito conciliado de '.self::brl($credited).' e saldo pendente de '.self::brl($outstanding).'. '
                .'Solicitamos a continuidade deste atendimento para investigar e pagar o ressarcimento devido. '
                .'Caso a Amazon considere o valor já pago, solicitamos a data, o valor, o ID da transação e/ou do ressarcimento e o relatório financeiro correspondente.';
        }
        if($action==='SELLER_SUPPORT_UPDATE'
            && $reason==='SUPPORT_CLAIMED_REIMBURSEMENT_NOT_RECONCILED'){
            $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
            $credited=max(0.0,(float)($case['reconciled_credit_amount']??0));
            $outstanding=max(0.0,$expected-$credited);
            return 'Sobre o pedido '.$order.'. Nós verificamos novamente nossa conciliação financeira e o ressarcimento informado pela Amazon ainda não está conciliado em nossa conta de vendedor. '
                .'Valor esperado: '.self::brl($expected).'. Crédito conciliado: '.self::brl($credited).'. Saldo pendente: '.self::brl($outstanding).'. '
                .'Solicitamos que a Amazon informe a data do crédito, o valor efetivamente creditado, o ID da transação financeira e/ou do ressarcimento e o relatório em que o lançamento aparece. '
                .'Enquanto esses dados não forem identificados e conciliados, o ressarcimento permanece pendente para nós.';
        }
        if($action==='SELLER_SUPPORT_UPDATE'
            && $reason==='SUPPORT_REIMBURSEMENT_DENIAL_REBUTTAL'){
            $expected=max(0.0,(float)($case['expected_reimbursement_amount']??0));
            $credited=max(0.0,(float)($case['reconciled_credit_amount']??0));
            $outstanding=max(0.0,$expected-$credited);
            return 'Sobre o pedido '.$order.'. Nós contestamos a negativa do ressarcimento porque o saldo do vendedor continua pendente após nova conciliação financeira. '
                .'Valor esperado: '.self::brl($expected).'. Crédito conciliado: '.self::brl($credited).'. Saldo pendente: '.self::brl($outstanding).'. '
                .'Solicitamos revisão manual e a indicação objetiva do fundamento da inelegibilidade aplicado a este pedido. '
                .'Se a Amazon entende que o item foi devolvido, recebido ou já ressarcido, solicitamos a evidência correspondente, incluindo rastreio/comprovante de entrega ou identificação do crédito financeiro.';
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
        if($action==='SELLER_SUPPORT_UPDATE'
            && $reason==='SUPPORT_RETURN_NOT_RECEIVED_REBUTTAL'){
            return 'Pedido '.$order.', SAFE-T '.$safeT.'. Nós não recebemos fisicamente o produto. '
                .'A resposta da Amazon afirma que o item foi devolvido e usa essa suposta devolução para aplicar o prazo de 7 dias, '
                .'mas essa informação não comprova que o item foi entregue a nós, vendedores. '
                .'O próprio histórico deste caso registra que o produto não retornou ao nosso estoque. '
                .'Solicitamos a revisão da decisão. Caso a Amazon considere que houve entrega ao vendedor, favor apresentar '
                .'a data e hora da entrega, a transportadora, o rastreio, o endereço de entrega, a identificação do recebedor e o comprovante de entrega. '
                .'Um evento de retorno à Amazon ou à transportadora não equivale a entrega ao vendedor.';
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
