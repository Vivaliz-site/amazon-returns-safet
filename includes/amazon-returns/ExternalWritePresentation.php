<?php
declare(strict_types=1);

/**
 * Projecao de observabilidade da outbox para o cockpit.
 * Nunca retorna erro bruto, payload, segredos ou muda elegibilidade/execucao.
 */
final class SvAmazonExternalWritePresentation
{
    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    public static function fromOutboxRow(?array $row): ?array
    {
        if ($row === null) return null;

        $status=strtoupper(trim((string)($row['status']??'')));
        $error=strtoupper(trim((string)($row['last_error']??'')));
        $attention=null;
        if ($status==='SUPERSEDED') {
            if (str_starts_with($error,'HUMAN_INTERVENTION_REQUIRED')) {
                $attention='HUMAN_INTERVENTION_REQUIRED';
            } elseif ($error==='SELLER_SUPPORT_SCOPE_SUPERSEDED') {
                $attention='SUPPORT_REFERENCE_CHANGED';
            } elseif (str_starts_with($error,'SUPERSEDED_BY_CURRENT_DECISION')) {
                $attention='DECISION_CHANGED';
            }
        } elseif (in_array($status,['PENDING','PROCESSING'],true)
            && in_array($error,[
                'UI_DRIFT: SUPPORT_WRITE_WITHOUT_READBACK_ID',
                'FAILED: SUPPORT_WRITE_WITHOUT_READBACK_ID',
            ],true)) {
            $attention='EXTERNAL_ID_UNCONFIRMED';
        }

        return [
            'id'=>(int)($row['id']??0),
            'kind'=>$row['kind']??null,
            'status'=>$row['status']??null,
            'updated_at'=>$row['updated_at']??null,
            'created_at'=>$row['created_at']??null,
            'attention_code'=>$attention,
        ];
    }
}
