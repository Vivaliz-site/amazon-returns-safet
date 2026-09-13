<?php
declare(strict_types=1);

interface SvAmazonErpSalesReturnGateway
{
    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function create(array $command): array;

    /** @return array<string,mixed>|null */
    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array;
}

final class SvAmazonUnverifiedErpSalesReturnGateway implements SvAmazonErpSalesReturnGateway
{
    public function create(array $command): array
    {
        return [
            'ok'=>false,
            'uncertain'=>false,
            'error_code'=>'ERP_SALES_RETURN_WRITE_NOT_VERIFIED',
            'error_message'=>'A operacao de Devolucoes de venda do Olist/Tiny ainda nao foi verificada para escrita automatica.',
        ];
    }

    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array
    {
        return null;
    }
}
