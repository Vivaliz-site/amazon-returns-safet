<?php
declare(strict_types=1);

final class SvAmazonReturnsHttpJsonRequest
{
    /** @return array<string|int,mixed> */
    public static function decodeObject(string $raw,int $maxBytes): array
    {
        if($maxBytes<1)throw new InvalidArgumentException('Maximum request size must be positive.');
        if(strlen($raw)>$maxBytes)throw new LengthException('Request body exceeds maximum size.');
        try{$decoded=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(JsonException $e){
            throw new UnexpectedValueException('Request body is not valid JSON.',0,$e);
        }
        if(!is_array($decoded))throw new UnexpectedValueException('Request JSON must decode to an object or array.');
        return $decoded;
    }
}
