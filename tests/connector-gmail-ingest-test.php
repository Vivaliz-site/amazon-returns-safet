<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$script=$root.'/scripts/amazon-returns/ingest-connector-gmail.php';
if(!is_file($script)){fwrite(STDERR,"missing connector Gmail ingest script\n");exit(1);}
$fixture=[
    'id'=>'connector-msg-1',
    'thread_id'=>'connector-thread-1',
    'from_'=>'Amazon Seller Support merch.service05@amazon.com.br',
    'subject'=>'[Case ID:22403666441]*Your Help Needed* FBA Returns Reimbursement',
    'body'=>'Estou cuidando do seu caso 22403666441 sobre o reembolso referente ao pedido 702-3172035-4814644. Precisamos de uma confirmação sua e aguardamos seu retorno.',
    'email_ts'=>'2026-10-06T07:00:52-03:00',
];
$tmp=tempnam(sys_get_temp_dir(),'gmail-connector-test-');
file_put_contents($tmp,json_encode($fixture,JSON_THROW_ON_ERROR));
$cmd='php '.escapeshellarg($script).' --parse-only < '.escapeshellarg($tmp);
exec($cmd,$lines,$code);
@unlink($tmp);
if($code!==0){fwrite(STDERR,"parse-only command failed\n");exit(1);}
$out=json_decode(implode("\n",$lines),true);
$event=$out['parsed'][0]??null;
$checks=[
    'ok'=>($out['ok']??false)===true,
    'one message'=>($out['messages']??0)===1,
    'one event'=>($out['events']??0)===1,
    'seller support type'=>is_array($event)&&($event['event_type']??null)==='SELLER_SUPPORT_EMAIL',
    'order id'=>is_array($event)&&($event['order_id']??null)==='702-3172035-4814644',
    'support case id'=>is_array($event)&&($event['support_case_id']??null)==='22403666441',
    'action required'=>is_array($event)&&($event['seller_action_required']??false)===true,
    'no body leak'=>!str_contains(implode("\n",$lines),'Precisamos de uma confirmação'),
];
foreach($checks as $name=>$ok){if(!$ok){fwrite(STDERR,"FAIL: $name\n");exit(1);}}
echo "connector-gmail-ingest-test: OK\n";
