<?php
declare(strict_types=1);

require_once __DIR__.'/Config.php';
require_once __DIR__.'/ReviewAdvisor.php';
require_once __DIR__.'/HybridReviewAdvisor.php';
require_once __DIR__.'/ReviewMemoryContext.php';
require_once __DIR__.'/GmailApi.php';

/**
 * Keeps the human-review queue operator-ready without weakening decision gates.
 * Genuine reviews receive an AI recommendation when possible. Operator reminders
 * continue every two hours even if every AI provider is temporarily unavailable.
 */
final class SvAmazonReviewOperations
{
    private const CURSOR_SOURCE='REVIEW_OPERATIONS';
    private const CURSOR_KEY='last_notification_at';
    private const REMINDER_SECONDS=7200;

    public function __construct(
        private object $persistence,
        private SvAmazonReturnsConfig $config,
        private ?SvAmazonReviewAdvisor $advisor=null,
        private ?SvAmazonGmailApiClient $gmail=null
    ) {}

    /** @return array<string,int|string> */
    public function run(?DateTimeImmutable $now=null):array
    {
        $now=($now??new DateTimeImmutable('now',new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $rows=$this->persistence->reviews->openQueue();
        $result=[
            'status'=>'OK','open_reviews'=>count($rows),'suggested'=>0,'ai_failed'=>0,
            'ready_reviews'=>0,'reminder_sent'=>0,'reminder_skipped'=>0,'notification_failed'=>0,
        ];
        if($rows===[]){
            $this->persistence->cursors->clear(self::CURSOR_SOURCE,self::CURSOR_KEY);
            return $result;
        }

        $notifyRows=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            if(is_array($row['ai_suggestion']??null)){$notifyRows[]=$row;$result['ready_reviews']++;continue;}
            if(!$this->config->reviewAiReady()){
                $result['ai_failed']++;
                $result['status']='PARTIAL';
                $notifyRows[]=$row;
                continue;
            }
            try{
                $advisor=$this->advisor??=new SvAmazonHybridReviewAdvisor($this->config);
                $context=(new SvAmazonReviewMemoryContext($this->persistence))->enrich(
                    is_array($row['context']??null)?$row['context']:[]
                );
                $suggestion=$advisor->suggest($context);
                $model=method_exists($advisor,'model')?trim((string)$advisor->model()):'review-advisor';
                if($model==='')$model='review-advisor';
                $provider=method_exists($advisor,'provider')?strtoupper(trim((string)$advisor->provider())):'OPENAI';
                if($provider==='')$provider='OPENAI';
                $saved=$this->persistence->reviews->saveSuggestion(
                    (int)$row['id'],(int)($row['version']??0),$suggestion,$model,$provider
                );
                $notifyRows[]=$saved;
                $result['suggested']++;
                $result['ready_reviews']++;
            }catch(Throwable $e){
                try{
                    $failed=$this->persistence->reviews->recordAiFailure(
                        (int)$row['id'],(int)($row['version']??0),$e::class
                    );
                    if(is_array($failed))$row=$failed;
                }catch(Throwable){}
                $notifyRows[]=$row;
                $result['ai_failed']++;
                $result['status']='PARTIAL';
            }
        }

        $last=$this->persistence->cursors->load(self::CURSOR_SOURCE,self::CURSOR_KEY);
        if(!$this->reminderDue($last,$now)){
            $result['reminder_skipped']=1;
            return $result;
        }
        $to=trim($this->config->get('AMAZON_RETURNS_REVIEW_NOTIFY_EMAIL'));
        if(filter_var($to,FILTER_VALIDATE_EMAIL)===false){
            $result['notification_failed']=1;
            $result['status']='PARTIAL';
            return $result;
        }

        try{
            $gmail=$this->gmail??=new SvAmazonGmailApiClient($this->config);
            $episode=is_array($last)?trim((string)($last['value']??'')):'new-episode';
            if($episode==='')$episode='new-episode';
            $identity=[];
            foreach($notifyRows as $row)$identity[]=(int)$row['id'].':'.(int)($row['version']??0);
            sort($identity,SORT_STRING);
            $ctx=method_exists($this->persistence,'context')?$this->persistence->context():null;
            $tenant=is_object($ctx)&&method_exists($ctx,'tenantId')?(int)$ctx->tenantId():0;
            $connection=is_object($ctx)&&method_exists($ctx,'amazonConnectionId')?(int)$ctx->amazonConnectionId():0;
            $key=hash('sha256',implode('|',['review-reminder-v1',$tenant,$connection,$to,$episode,implode(',',$identity)]));
            $count=count($notifyRows);
            $subject='Amazon Returns: '.$count.' '.($count===1?'revisão pendente':'revisões pendentes');
            $sent=$gmail->sendOnce($to,$subject,$this->message($notifyRows),$key);
            $this->persistence->cursors->save(
                self::CURSOR_SOURCE,self::CURSOR_KEY,$now->format(DATE_ATOM),[
                    'review_count'=>$count,
                    'review_ids'=>array_values(array_map(static fn(array $r):int=>(int)$r['id'],$notifyRows)),
                    'gmail_message_id'=>(string)($sent['message_id']??''),
                ]
            );
            $result['reminder_sent']=1;
        }catch(Throwable){
            $result['notification_failed']=1;
            $result['status']='PARTIAL';
        }
        return $result;
    }

    /** @param array{value?:string}|null $last */
    private function reminderDue(?array $last,DateTimeImmutable $now):bool
    {
        $raw=is_array($last)?trim((string)($last['value']??'')):'';
        if($raw==='')return true;
        try{$at=(new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'));}
        catch(Throwable){return true;}
        return $now->getTimestamp()-$at->getTimestamp()>=self::REMINDER_SECONDS;
    }

    /** @param list<array<string,mixed>> $rows */
    private function message(array $rows):string
    {
        $count=count($rows);
        $lines=[
            'Há '.$count.' '.($count===1?'revisão pendente':'revisões pendentes').' no Amazon Returns / SAFE-T.',
            'O sistema tenta gerar uma recomendação de IA automaticamente. Quando ela estiver indisponível, a revisão continua visível e exige sua decisão.',
            '',
        ];
        foreach($rows as $row){
            $case=$this->persistence->cases->find((int)$row['case_id']);
            $order=is_array($case)?trim((string)($case['amazon_order_id']??'')):'';
            $suggestion=is_array($row['ai_suggestion']??null)?$row['ai_suggestion']:null;
            $confidence=$suggestion!==null&&is_numeric($suggestion['confidence']??null)?(int)round((float)$suggestion['confidence']*100):null;
            $line='Revisão #'.(int)$row['id'];
            if($order!=='')$line.=' · Pedido '.$order;
            $line.=$suggestion===null?' · Sugestão da IA: indisponível no momento':' · Sugestão da IA: '.$this->actionLabel((string)($suggestion['action']??''));
            if($confidence!==null)$line.=' · Confiança '.$confidence.'%';
            $lines[]=$line;
        }
        $lines[]='';
        $lines[]='Acesse: https://returns.shopvivaliz.com.br/admin/amazon-returns/?view=reviews';
        $lines[]='Este lembrete será repetido a cada 2 horas enquanto houver revisão pendente.';
        return implode("\n",$lines);
    }

    private function actionLabel(string $action):string
    {
        return [
            'WAIT'=>'Aguardar',
            'CHECK_FINANCES'=>'Verificar financeiro',
            'SAFE_T_SUBMIT'=>'Solicitar ressarcimento SAFE-T',
            'SAFE_T_APPEAL'=>'Recorrer no SAFE-T',
            'SAFE_T_EMAIL_REVIEW'=>'Revisar e-mail do SAFE-T',
            'SAFE_T_EMAIL_REPLY'=>'Responder e-mail do SAFE-T',
            'SELLER_SUPPORT_OPEN'=>'Abrir chamado no Suporte ao Vendedor',
            'SELLER_SUPPORT_UPDATE'=>'Atualizar chamado no Suporte ao Vendedor',
            'CLOSE_LOSS'=>'Encerrar como perda',
        ][$action]??'Revisar situação';
    }
}