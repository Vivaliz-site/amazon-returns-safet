<?php
declare(strict_types=1);

final class SvAmazonReturnsPublicHealthResponse
{
    /** @param array<string,mixed> $runtimeHealth @return array{service:string,status:string} */
    public static function fromRuntime(array $runtimeHealth): array
    {
        $status = strtoupper(trim((string)($runtimeHealth['status'] ?? '')));
        if (!in_array($status,['OK','DEGRADED'],true)) $status = 'FAILED';

        return [
            'service'=>'amazon-returns-safet',
            'status'=>$status,
        ];
    }
}
