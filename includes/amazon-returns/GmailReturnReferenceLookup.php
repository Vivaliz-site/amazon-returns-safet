<?php
declare(strict_types=1);

require_once __DIR__.'/GmailApi.php';
require_once __DIR__.'/GmailParser.php';

final class SvAmazonGmailReturnReferenceLookup
{
    public function __construct(private SvAmazonGmailApiClient $gmail) {}

    /** @return array{order_id:string,event:array<string,mixed>}|null */
    public function find(string $tbr): ?array
    {
        $tbr=strtoupper(trim($tbr));
        if(preg_match('/^TBR[A-Z0-9-]{6,30}$/',$tbr)!==1){
            throw new InvalidArgumentException('Invalid Amazon return tracking reference.');
        }
        $parser=new SvAmazonGmailParser();
        foreach($this->gmail->searchMessages('"'.$tbr.'"',500) as $message){
            foreach($parser->parse($message) as $event){
                if(($event['event_type']??'')!=='RETURN_AUTHORIZED_EMAIL')continue;
                if(strtoupper(trim((string)($event['return_tracking_id']??'')))!==$tbr)continue;
                $orderId=trim((string)($event['order_id']??''));
                if($orderId==='')continue;
                return ['order_id'=>$orderId,'event'=>$event];
            }
        }
        return null;
    }
}
