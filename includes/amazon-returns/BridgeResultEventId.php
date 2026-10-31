<?php
declare(strict_types=1);

/**
 * Immutable event identity for each Seller Central bridge result, distinct from
 * outbox job idempotency. External result replay uses the same result_event_id.
 * Legacy worker responses omit the ID and retain deterministic content identity.
 */
final class SvAmazonBridgeResultEventId
{
    /** @param array<string,mixed> $row @param array<string,mixed> $result */
    public static function key(string $scopeKey,array $row,array $result): string
    {
        if(trim($scopeKey)==='')throw new InvalidArgumentException('Scoped bridge result identity requires a scope.');
        $resultEventId=$result['result_event_id']??null;
        if($resultEventId!==null
            && (!is_string($resultEventId)
                || preg_match('/^[a-f0-9]{32}$/D',$resultEventId)!==1)){
            throw new InvalidArgumentException('Bridge result event ID must be 32 lowercase hexadecimal characters.');
        }
        $rowPayload=is_array($row['payload']??null)?$row['payload']:[];
        $writeSnapshot=is_array($rowPayload['write_snapshot']??null)?$rowPayload['write_snapshot']:[];
        $writeHash=strtolower(trim((string)($writeSnapshot['content_sha256']??'')));
        if(preg_match('/^[a-f0-9]{64}$/D',$writeHash)!==1)$writeHash=null;
        $evidence=is_array($result['evidence']??null)?$result['evidence']:[];
        $evidenceHash=strtolower(trim((string)($evidence['snapshot_sha256']??'')));
        if(preg_match('/^[a-f0-9]{64}$/D',$evidenceHash)!==1)$evidenceHash=null;
        $normalized=[
            'schema'=>'SELLER_CENTRAL_RESULT_V3',
            'scope'=>$scopeKey,
            'outbox_id'=>(int)($row['id']??0),
            'outbox_idempotency_key'=>(string)($row['idempotency_key']??''),
            'kind'=>(string)($row['kind']??''),
            'write_content_sha256'=>$writeHash,
            'result_event_id'=>$resultEventId,
            'status'=>(string)($result['status']??''),
            'submitted'=>($result['submitted']??false)===true,
            'external_id'=>$result['external_id']??null,
            'retry_safe'=>($result['retry_safe']??false)===true,
            'reason'=>$result['reason']??null,
            'block_reason'=>$result['block_reason']??null,
            'next_allowed_at'=>$result['next_allowed_at']??null,
            'evidence_sha256'=>$evidenceHash,
        ];
        return hash('sha256','seller-central-result-v3|'.json_encode(
            $normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        ));
    }
}
