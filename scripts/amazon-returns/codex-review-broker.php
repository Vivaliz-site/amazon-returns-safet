<?php
declare(strict_types=1);

$socket=getenv('AMAZON_RETURNS_CODEX_REVIEW_SOCKET')?:'/run/amazon-returns-safet/codex-review.sock';
$wrapper=__DIR__.'/codex-review-wrapper.sh';
if(!is_file($wrapper)||!is_executable($wrapper))throw new RuntimeException('Codex review wrapper unavailable.');
if(file_exists($socket))@unlink($socket);
$server=stream_socket_server('unix://'.$socket,$errno,$errstr,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
if(!is_resource($server))throw new RuntimeException('Unable to start Codex review broker.');
@chmod($socket,0660);

while(true){
    $conn=@stream_socket_accept($server,-1);
    if(!is_resource($conn))continue;
    try{
        stream_set_timeout($conn,40);
        $raw=stream_get_contents($conn,131073);
        if(!is_string($raw)||trim($raw)===''||strlen($raw)>131072)throw new RuntimeException('Invalid Codex review request.');
        $request=json_decode(trim($raw),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($request)||array_diff(array_keys($request),['context','schema'])!==[]||!is_array($request['context']??null)||!is_array($request['schema']??null))throw new RuntimeException('Malformed Codex review request.');
        $context=json_encode($request['context'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($context)>131072)throw new RuntimeException('Codex review context too large.');
        $process=proc_open([$wrapper],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,__DIR__,[]);
        if(!is_resource($process))throw new RuntimeException('Unable to start Codex review wrapper.');
        fwrite($pipes[0],$context);fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2],2048);fclose($pipes[2]);
        $exit=proc_close($process);
        if($exit!==0||!is_string($stdout)||trim($stdout)==='')throw new RuntimeException('Codex review wrapper failed.');
        $decoded=json_decode(trim($stdout),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($decoded)||!is_array($decoded['suggestion']??null))throw new RuntimeException('Codex review response malformed.');
        $output=json_encode($decoded,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(strlen($output)>131072)throw new RuntimeException('Codex review response too large.');
        fwrite($conn,$output."\n");
    }catch(Throwable $e){
        $safe=['error'=>'CODEX_REVIEW_UNAVAILABLE','error_class'=>$e::class];
        @fwrite($conn,json_encode($safe,JSON_UNESCAPED_SLASHES)."\n");
    }finally{
        fclose($conn);
    }
}
