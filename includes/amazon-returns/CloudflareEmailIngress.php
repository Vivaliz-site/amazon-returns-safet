<?php
declare(strict_types=1);

require_once __DIR__ . '/GmailParser.php';

final class SvAmazonCloudflareEmailIngress
{
    /** @return array<string,mixed> */
    public static function normalize(array $input): array
    {
        $messageId=trim((string)($input['message_id'] ?? ''));
        $from=trim((string)($input['from'] ?? ''));
        $subject=trim((string)($input['subject'] ?? ''));
        $body=(string)($input['body_text'] ?? '');
        if($messageId==='' || $from==='' || $subject==='' || trim($body)===''){
            throw new InvalidArgumentException('Cloudflare email payload is incomplete.');
        }
        if(strlen($messageId)>512 || strlen($from)>2048 || strlen($subject)>8192 || strlen($body)>262144){
            throw new LengthException('Cloudflare email payload field is too large.');
        }
        return [
            'source'=>'CLOUDFLARE_EMAIL',
            'message_id'=>$messageId,
            'thread_id'=>trim((string)($input['thread_id'] ?? '')),
            'rfc_message_id'=>trim((string)($input['rfc_message_id'] ?? '')),
            'from'=>$from,
            'subject'=>$subject,
            'received_at'=>trim((string)($input['received_at'] ?? '')),
            'body_text'=>$body,
            'snippet'=>trim((string)($input['snippet'] ?? '')),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function parse(array $input): array
    {
        return (new SvAmazonGmailParser())->parse(self::normalize($input));
    }
}
