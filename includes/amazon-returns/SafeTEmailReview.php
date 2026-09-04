<?php

declare(strict_types=1);

require_once __DIR__ . '/SafeTStatus.php';

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
        $lines[] = 'O recurso no fluxo SAFE-T já foi analisado e negado. Solicito nova revisão manual do histórico do pedido, da devolução e do débito, com ressarcimento quando devido.';
        $lines[] = 'Caso a Amazon considere que o item foi devolvido/entregue ao vendedor, solicito informar a data, rastreamento, transportadora e comprovante de entrega utilizados nessa conclusão.';
        $lines[] = '';
        $lines[] = 'Atenciosamente,';
        $lines[] = 'ShopVivaLiz';

        return ['to'=>self::RECIPIENT,'subject'=>$subject,'body'=>implode("\n", $lines)];
    }
}
