<?php
declare(strict_types=1);

final class SvAmazonCockpitConversation
{
    /** @return list<array<string,mixed>> */
    public static function project(array $timeline):array
    {
        $messages=[];$seen=[];
        foreach($timeline as $item){
            if(!is_array($item))continue;
            $content=is_array($item['content']??null)?$item['content']:[];
            $observedAt=self::dateOrNull($item['occurred_at']??null);
            foreach(is_array($content['communications']??null)?$content['communications']:[] as $index=>$message){
                if(!is_array($message))continue;
                self::append($messages,$seen,[
                    'id'=>(string)($item['id']??'event').':communication:'.$index,
                    'actor'=>self::actor($message['actor']??null),
                    'channel'=>'SAFE_T','kind'=>(string)($message['kind']??'MESSAGE'),
                    'subject'=>null,'body'=>(string)($message['body']??''),
                    'occurred_at'=>self::dateOrNull($message['occurred_at']??null),
                    'observed_at'=>$observedAt,'thread_id'=>null,
                    'status'=>$item['status']??null,'source'=>$item['source']??null,
                ]);
            }
            self::appendTimelineMessage($messages,$seen,$item,$content,$observedAt);
        }
        usort($messages,static fn(array $a,array $b):int=>strcmp((string)($a['occurred_at']??$a['observed_at']??''),(string)($b['occurred_at']??$b['observed_at']??'')));
        return array_values($messages);
    }

    private static function appendTimelineMessage(array &$messages,array &$seen,array $item,array $content,?string $observedAt):void
    {
        $category=strtoupper(trim((string)($item['category']??'')));
        $source=strtoupper(trim((string)($item['source']??'')));
        if($category==='EXTERNAL_WRITE'){
            $action=strtoupper(trim((string)($content['action']??'')));
            $message=is_array($content['message']??null)?$content['message']:[];
            $body=trim((string)($message['body']??$content['narrative']??''));
            if($body==='')return;
            self::append($messages,$seen,[
                'id'=>(string)($item['id']??'write'),'actor'=>'SELLER','channel'=>self::channel($action,$source),
                'kind'=>$action?:'MESSAGE','subject'=>$message['subject']??null,'body'=>$body,
                'occurred_at'=>$observedAt,'observed_at'=>$observedAt,'thread_id'=>$message['thread_id']??null,
                'status'=>$item['status']??null,'source'=>$item['source']??null,
            ]);
            return;
        }
        if($source!=='GMAIL'&&$category!=='AMAZON_RESPONSE')return;
        $body=trim((string)($content['review_excerpt']??$content['narrative']??''));
        if($body==='')return;
        self::append($messages,$seen,[
            'id'=>(string)($item['id']??'response'),'actor'=>'AMAZON','channel'=>$source==='GMAIL'?'EMAIL':'SAFE_T',
            'kind'=>'RESPONSE','subject'=>null,'body'=>$body,'occurred_at'=>$observedAt,'observed_at'=>$observedAt,
            'thread_id'=>$content['gmail_thread_id']??null,'status'=>$item['status']??null,'source'=>$item['source']??null,
        ]);
    }

    private static function append(array &$messages,array &$seen,array $message):void
    {
        $body=trim((string)($message['body']??''));$actor=self::actor($message['actor']??null);
        if($body===''||$actor===null)return;
        $key=$actor.'|'.strtoupper((string)($message['channel']??'')).'|'.mb_strtolower(preg_replace('/\s+/u',' ',$body)??$body,'UTF-8');
        if(isset($seen[$key]))return;
        $seen[$key]=true;$message['actor']=$actor;$message['body']=$body;$messages[]=$message;
    }

    private static function actor(mixed $actor):?string
    {
        return match(strtoupper(trim((string)$actor))){'SELLER','SHOP','LOJA'=>'SELLER','AMAZON'=>'AMAZON',default=>null};
    }

    private static function channel(string $action,string $source):string
    {
        if(str_starts_with($action,'SELLER_SUPPORT_'))return 'SELLER_SUPPORT';
        if(str_contains($action,'EMAIL'))return 'EMAIL';
        return $source==='GMAIL'?'EMAIL':'SAFE_T';
    }

    private static function dateOrNull(mixed $value):?string
    {
        if(!is_scalar($value)||trim((string)$value)==='')return null;
        return trim((string)$value);
    }
}
