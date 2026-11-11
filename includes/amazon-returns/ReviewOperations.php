<?php
declare(strict_types=1);

require_once __DIR__.'/Config.php';
require_once __DIR__.'/ReviewAdvisor.php';
require_once __DIR__.'/OpenAiReviewAdvisor.php';
require_once __DIR__.'/GmailApi.php';

/**
 * Enriches operational investigations with AI advice but notifies the owner only
 * when a genuine manual decision exists. Notifications are event-based, not
 * repeated while the same decision stays open. The operational queue remains
 * intact for the scheduler, finance and Seller Central recovery workers.
 */
final class SvAmazonReviewOperations
{
    private const CURSOR_SOURCE='REVIEW_OPERATIONS';
    private const CURSOR_KEY='last_notification_at';

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
            'ready_reviews'=>0,'operational_reviews'=>0,'manual_reviews'=>0,
            'reminder_sent'=>0,'reminder_skipped'=>0,'notification_failed'=>0,
        ];
        if($rows===[]){
            $this->persistence->cursors->clear(self::CURSOR_SOURCE,self::CURSOR_KEY);
            return $result;
        }

        $ready=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            if(is_array($row['ai_suggestion']??null)){
                // Saved AI suggestions can become invalid as official appeal deadlines pass.
                // Repair only the advisory, preserving the case, claim and review evidence.
                try{
                    $context=is_array($row['context']??null)?$row['context']:[];
                    $guarded=SvAmazonOpenAiReviewAdvisor::guardSuggestion($row['ai_suggestion'],$context,$now);
                    if($guarded!==$row['ai_suggestion']){
                        $model=trim((string)($row['ai_model']??''))?:'review-advisor';
                        $row=$this->persistence->reviews->saveSuggestion(
                            (int)$row['id'],(int)($row['version']??0),$guarded,$model
                        );
                        $result['suggested']++;
                    }
                    $ready[]=$row;
                }catch(Throwable){
                    $result['ai_failed']++;
                    $result['status']='PARTIAL';
                }
                continue;
            }
            if(!$this->config->reviewAiReady()){
                $result['ai_failed']++;
                $result['status']='PARTIAL';
                continue;
            }
            try{
                $advisor=$this->advisor??=new SvAmazonOpenAiReviewAdvisor($this->config);
                $suggestion=$advisor->suggest(is_array($row['context']??null)?$row['context']:[]);
                $model=method_exists($advisor,'model')?trim((string)$advisor->model()):'review-advisor';
                if($model==='')$model='review-advisor';
                $saved=$this->persistence->reviews->saveSuggestion(
                    (int)$row['id'],(int)($row['version']??0),$suggestion,$model
                );
                $ready[]=$saved;
                $result['suggested']++;
            }catch(Throwable $e){
                try{
                    $this->persistence->reviews->recordAiFailure(
                        (int)$row['id'],(int)($row['version']??0),$e::class
                    );
                }catch(Throwable){}
                $result['ai_failed']++;
                $result['status']='PARTIAL';
            }
        }
        $result['ready_reviews']=count($ready);
        // An open recovery investigation is not a request for owner approval.
        // Do not silently resolve financial/support cases merely to stop email.
        $manualRows=array_values(array_filter($rows,self::requiresManualDecision(...)));
        $result['manual_reviews']=count($manualRows);
        $result['operational_reviews']=count($rows)-count($manualRows);
        $readyManual=array_values(array_filter($ready,self::requiresManualDecision(...)));
        if($readyManual===[]){
            $result['reminder_skipped']=1;
            return $result;
        }

        $last=$this->persistence->cursors->load(self::CURSOR_SOURCE,self::CURSOR_KEY);
        $previous=is_array($last['metadata']??null)?$last['metadata']:[];
        // Support the previous cursor, whose review_ids included operational work.
        $priorIds=$previous['notified_review_ids']??$previous['review_ids']??[];
        if(!is_array($priorIds))$priorIds=[];
        $notified=array_fill_keys(array_map('intval',$priorIds),true);
        $newManual=array_values(array_filter(
            $readyManual,static fn(array $review):bool=>!isset($notified[(int)$review['id']])
        ));
        if($newManual===[]){
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
            $identity=array_map(static fn(array $r):int=>(int)$r['id'],$newManual);
            sort($identity,SORT_NUMERIC);
            $ctx=method_exists($this->persistence,'context')?$this->persistence->context():null;
            $tenant=is_object($ctx)&&method_exists($ctx,'tenantId')?(int)$ctx->tenantId():0;
            $connection=is_object($ctx)&&method_exists($ctx,'amazonConnectionId')?(int)$ctx->amazonConnectionId():0;
            // Stable ID set makes uncertain Gmail retries idempotent.
            $key=hash('sha256',implode('|',['owner-review-new-v2',$tenant,$connection,$to,implode(',',$identity)]));
            $count=count($newManual);
            $subject='Amazon Returns: '.$count.' '.($count===1?'decisão humana necessária':'decisões humanas necessárias');
            $sent=$gmail->sendOnce($to,$subject,$this->message($newManual),$key);
            foreach($identity as $id)$notified[$id]=true;
            $notifiedIds=array_map('intval',array_keys($notified));
            sort($notifiedIds,SORT_NUMERIC);
            $this->persistence->cursors->save(
                self::CURSOR_SOURCE,self::CURSOR_KEY,$now->format(DATE_ATOM),[
                    'review_count'=>$count,
                    'review_ids'=>$notifiedIds,
                    'notified_review_ids'=>$notifiedIds,
                    'new_review_ids'=>$identity,
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

    /** A manual exception is evidence-based, not implied by an OPEN status or AI suggestion. */
    public static function requiresManualDecision(array $review):bool
    {
        $context=is_array($review['context']??null)?$review['context']:[];
        $signature=is_array($context['signature']??null)?$context['signature']:[];
        $facts=is_array($context['facts']??null)?$context['facts']:[];
        if(($signature['material_conflict']??false)===true || ($facts['material_conflict']??false)===true){
            return true;
        }
        $reason=strtoupper(trim((string)($review['reason']??$context['review_reason']??'')));
        return in_array($reason,[
            'DAMAGED_RETURN_INITIAL_CLAIM_MANUAL_ONLY',
            'EMAIL_REVIEW_FINAL_REQUIRES_TERMINAL_REVIEW',
            'EMAIL_REVIEW_REQUIRES_HUMAN_DECISION',
        ],true);
    }

    /** @param list<array<string,mixed>> $rows */
    private function message(array $rows):string
    {
        $count=count($rows);
        $lines=[
            'Há '.$count.' '.($count===1?'decisão humana necessária':'decisões humanas necessárias').' no Amazon Returns / SAFE-T.',
            'Somente exceções que realmente dependem de sua decisão são incluídas nesta mensagem.',
            '',
        ];
        foreach($rows as $row){
            $case=$this->persistence->cases->find((int)$row['case_id']);
            $order=is_array($case)?trim((string)($case['amazon_order_id']??'')):'';
            $suggestion=is_array($row['ai_suggestion']??null)?$row['ai_suggestion']:[];
            $confidence=is_numeric($suggestion['confidence']??null)?(int)round((float)$suggestion['confidence']*100):null;
            $line='Revisão #'.(int)$row['id'];
            if($order!=='')$line.=' · Pedido '.$order;
            $line.=' · Sugestão da IA: '.$this->actionLabel((string)($suggestion['action']??''));
            if($confidence!==null)$line.=' · Confiança '.$confidence.'%';
            $facts=is_array($row['context']['facts']??null)?$row['context']['facts']:[];
            if(array_key_exists('customer_delivery_status',$facts)){
                $delivery=(string)$facts['customer_delivery_status'];
                $line.=' · Entrega ao cliente: '.match($delivery){
                    'DELIVERED'=>'confirmada',
                    'PARTIALLY_DELIVERED'=>'parcial',
                    'IN_TRANSIT'=>'em trânsito',
                    'OUT_FOR_DELIVERY'=>'em rota de entrega',
                    'DELIVERY_ATTEMPTED'=>'tentativa registrada',
                    'RETURNED_TO_SENDER'=>'retorno indicado no rastreio',
                    'UNDELIVERABLE'=>'entrega impossível',
                    default=>'não verificada',
                };
                $outbound=is_array($facts['customer_tracking_ids']??null)?$facts['customer_tracking_ids']:[];
                $return=is_array($facts['return_tracking_ids']??null)?$facts['return_tracking_ids']:[];
                if($outbound!==[])$line.=' · Rastreio de envio disponível';
                if($return!==[])$line.=' · Rastreio de devolução disponível';
            }
            $lines[]=$line;
        }
        $lines[]='';
        $lines[]='A entrega ao comprador não comprova retorno físico à ShopVivaliz nem crédito de ressarcimento. Confirme as evidências antes de decidir.';
        $lines[]='Acesse: https://returns.shopvivaliz.com.br/admin/amazon-returns/?view=reviews';
        $lines[]='As pendências operacionais permanecem registradas no sistema para investigação e processamento automático.';
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
