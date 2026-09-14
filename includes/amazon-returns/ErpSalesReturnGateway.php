<?php
declare(strict_types=1);

interface SvAmazonErpSalesReturnGateway
{
    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function create(array $command): array;

    /** @return array<string,mixed>|null */
    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array;
}

final class SvAmazonUnverifiedErpSalesReturnGateway implements SvAmazonErpSalesReturnGateway
{
    public function create(array $command): array
    {
        return [
            'ok'=>false,
            'uncertain'=>false,
            'error_code'=>'ERP_SALES_RETURN_WRITE_NOT_VERIFIED',
            'error_message'=>'A operacao de Devolucoes de venda do Olist/Tiny ainda nao foi verificada para escrita automatica.',
        ];
    }

    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array
    {
        return null;
    }
}

final class SvAmazonOlistBrowserErpSalesReturnGateway implements SvAmazonErpSalesReturnGateway
{
    /** @var callable(array<string,mixed>):array<string,mixed>|null */
    private $runner;
    private string $cdpUrl;

    /** @param callable(array<string,mixed>):array<string,mixed>|null $runner */
    public function __construct(?callable $runner=null, ?string $cdpUrl=null)
    {
        $this->runner=$runner;
        $url=trim((string)($cdpUrl??getenv('OLIST_ERP_CDP_URL')?:'http://127.0.0.1:9226'));
        $this->cdpUrl=$url;
    }

    public function create(array $command): array
    {
        $sale=is_array($command['original_sale']??null)?$command['original_sale']:[];
        $orderId=trim((string)($command['amazon_order_id']??''));
        $invoiceId=trim((string)($sale['invoice_id']??''));
        $refundAt=substr(trim((string)($command['refund_at']??'')),0,10);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1 || preg_match('/^[0-9]+$/D',$invoiceId)!==1 || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$refundAt)!==1){
            return ['ok'=>false,'uncertain'=>false,'error_code'=>'ERP_SALES_RETURN_COMMAND_INVALID','error_message'=>'Os dados da devolucao no ERP estao incompletos.'];
        }
        $result=$this->run([
            'action'=>'CREATE','amazon_order_id'=>$orderId,
            'original_invoice_id'=>$invoiceId,
            'original_invoice_number'=>trim((string)($sale['invoice_number']??'')),
            'refund_at'=>$refundAt,
            'items'=>is_array($command['items']??null)?$command['items']:[],
        ]);
        $status=strtoupper(trim((string)($result['status']??'')));
        $externalId=trim((string)($result['external_id']??''));
        if(in_array($status,['ACCEPTED','ALREADY_EXISTS'],true) && $externalId!==''){
            return ['ok'=>true,'uncertain'=>false,'id'=>$externalId];
        }
        $map=[
            'AUTH_REQUIRED'=>['ERP_SALES_RETURN_AUTH_REQUIRED','A sessao do Olist/Tiny precisa ser autenticada na VM.'],
            'UI_DRIFT'=>['ERP_SALES_RETURN_UI_DRIFT','A tela de devolucoes do Olist/Tiny mudou e a escrita foi bloqueada.'],
            'BROWSER_UNAVAILABLE'=>['ERP_SALES_RETURN_BROWSER_UNAVAILABLE','O navegador do Olist/Tiny na VM nao esta disponivel.'],
            'BROWSER_CONFIG_INVALID'=>['ERP_SALES_RETURN_BROWSER_UNAVAILABLE','O navegador do Olist/Tiny na VM nao esta configurado corretamente.'],
        ];
        [$code,$message]=$map[$status]??['ERP_SALES_RETURN_CREATE_FAILED','Nao foi possivel criar a devolucao automaticamente no Olist/Tiny.'];
        return ['ok'=>false,'uncertain'=>$externalId!=='' && (($result['retry_safe']??false)!==true),'id'=>$externalId!==''?$externalId:null,'error_code'=>$code,'error_message'=>$message];
    }

    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array
    {
        if(preg_match('/^[0-9]+$/D',trim($erpSalesReturnId))!==1)return null;
        $result=$this->run(['action'=>'READBACK','external_id'=>trim($erpSalesReturnId),'amazon_order_id'=>trim($amazonOrderId)]);
        return strtoupper(trim((string)($result['status']??'')))==='FOUND' && is_array($result['record']??null)?$result['record']:null;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function run(array $payload): array
    {
        if(is_callable($this->runner))return ($this->runner)($payload);
        return $this->runNodeAdapter($payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function runNodeAdapter(array $payload): array
    {
        $script=realpath(__DIR__.'/../../scripts/amazon-returns/erp-sales-return-browser.mjs');
        if($script===false)throw new RuntimeException('ERP sales return browser adapter script missing.');
        $env=getenv();
        if(!is_array($env))$env=[];
        $env['OLIST_ERP_CDP_URL']=$this->cdpUrl;
        $process=proc_open(['node',$script],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,dirname($script),$env);
        if(!is_resource($process))throw new RuntimeException('Unable to start ERP sales return browser adapter.');
        fwrite($pipes[0],json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
        $exit=proc_close($process);
        if($exit!==0)throw new RuntimeException('ERP sales return browser adapter failed: '.mb_substr(trim((string)$stderr),0,300,'UTF-8'));
        $result=json_decode((string)$stdout,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($result))throw new RuntimeException('ERP sales return browser adapter returned invalid payload.');
        return $result;
    }
}
