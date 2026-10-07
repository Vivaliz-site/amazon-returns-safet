<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/includes/Database.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/Config.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantRegistry.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/TenantPersistence.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/GmailParser.php';
require_once dirname(__DIR__,2).'/includes/amazon-returns/GmailEventSink.php';

function connectorGmailMessages(mixed $decoded): array
{
    if (!is_array($decoded)) throw new InvalidArgumentException('Input must be JSON object or array.');
    if (isset($decoded['messages'])) {
        if (!is_array($decoded['messages'])) throw new InvalidArgumentException('messages must be an array.');
        $messages=$decoded['messages'];
    } elseif (array_is_list($decoded)) {
        $messages=$decoded;
    } else {
        $messages=[$decoded];
    }
    if (count($messages)>100) throw new InvalidArgumentException('At most 100 Gmail messages per batch.');
    $normalized=[];
    foreach($messages as $message){
        if(!is_array($message))continue;
        $normalized[]=[
            'id'=>trim((string)($message['id']??$message['message_id']??'')),
            'message_id'=>trim((string)($message['message_id']??$message['id']??'')),
            'thread_id'=>trim((string)($message['thread_id']??'')),
            'rfc_message_id'=>trim((string)($message['rfc_message_id']??'')),
            'from'=>trim((string)($message['from']??$message['from_']??'')),
            'subject'=>trim((string)($message['subject']??'')),
            'body_text'=>(string)($message['body_text']??$message['body']??$message['snippet']??''),
            'snippet'=>(string)($message['snippet']??''),
            'received_at'=>$message['received_at']??$message['email_ts']??null,
        ];
    }
    return $normalized;
}

$raw=stream_get_contents(STDIN);
if($raw===false || trim($raw)===''){fwrite(STDERR,"JSON input required on stdin\n");exit(2);}
if(strlen($raw)>2_000_000){fwrite(STDERR,"Input too large\n");exit(2);}
try{
    $decoded=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    $messages=connectorGmailMessages($decoded);
    $parser=new SvAmazonGmailParser();
    $events=[];
    foreach($messages as $message){
        foreach($parser->parse($message) as $event)$events[]=$event;
    }
    $parseOnly=in_array('--parse-only',$argv,true);
    $persisted=[];
    if(!$parseOnly && $events!==[]){
        $db=amazon_returns_require_pdo();
        $config=new SvAmazonReturnsConfig();
        $context=SvAmazonTenantRegistry::resolveCurrent($db,$config);
        $p=SvAmazonTenantPersistence::create($db,$context);
        foreach($events as $event)$persisted[]=SvAmazonGmailEventSink::persist($p,$event);
    }
    $safeEvents=array_map(static fn(array $event):array=>[
        'event_type'=>$event['event_type']??null,
        'order_id'=>$event['order_id']??null,
        'safe_t_id'=>$event['safe_t_id']??null,
        'support_case_id'=>$event['support_case_id']??null,
        'seller_action_required'=>($event['seller_action_required']??false)===true,
        'source_event_id'=>$event['source_event_id']??null,
    ],$events);
    echo json_encode([
        'ok'=>true,
        'parse_only'=>$parseOnly,
        'messages'=>count($messages),
        'events'=>count($events),
        'event_ids'=>$persisted,
        'parsed'=>$safeEvents,
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
}catch(Throwable $e){
    fwrite(STDERR,'connector gmail ingest failed: '.$e->getMessage().PHP_EOL);
    exit(1);
}
