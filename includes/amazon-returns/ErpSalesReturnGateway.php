<?php
declare(strict_types=1);

interface SvAmazonErpSalesReturnGateway
{
    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function create(array $command): array;

    /** @return array<string,mixed>|null */
    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array;

    public function probeExisting(string $originalInvoiceId,string $originalInvoiceNumber=''): ?string;
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

    public function probeExisting(string $originalInvoiceId,string $originalInvoiceNumber=''): ?string
    {
        throw new RuntimeException('ERP sales return existence probe is not verified.');
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
            'ITEM_MAPPING_FAILED'=>['ERP_SALES_RETURN_ITEM_MAPPING_FAILED','Os itens reembolsados nao puderam ser correlacionados com seguranca aos itens da venda original no Olist/Tiny.'],
            'ADDRESS_NUMBER_REQUIRED'=>['ERP_SALES_RETURN_ADDRESS_NUMBER_REQUIRED','O Olist/Tiny rejeitou a devolucao porque o endereco da venda original nao possui numero confirmado.'],
        ];
        [$code,$message]=$map[$status]??['ERP_SALES_RETURN_CREATE_FAILED','Nao foi possivel criar a devolucao automaticamente no Olist/Tiny.'];
        return ['ok'=>false,'uncertain'=>$externalId!=='' && (($result['retry_safe']??false)!==true),'id'=>$externalId!==''?$externalId:null,'error_code'=>$code,'error_message'=>$message];
    }

    public function preflightCreate(array $command): bool
    {
        $sale=is_array($command['original_sale']??null)?$command['original_sale']:[];
        $orderId=trim((string)($command['amazon_order_id']??''));
        $invoiceId=trim((string)($sale['invoice_id']??''));
        $refundAt=substr(trim((string)($command['refund_at']??'')),0,10);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1 || preg_match('/^[0-9]+$/D',$invoiceId)!==1 || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$refundAt)!==1)return false;
        $result=$this->run([
            'action'=>'VALIDATE_CREATE','amazon_order_id'=>$orderId,
            'original_invoice_id'=>$invoiceId,
            'original_invoice_number'=>trim((string)($sale['invoice_number']??'')),
            'refund_at'=>$refundAt,
            'items'=>is_array($command['items']??null)?$command['items']:[],
        ]);
        $status=strtoupper(trim((string)($result['status']??'')));
        if($status==='READY')return true;
        if(in_array($status,['NOT_READY','ALREADY_EXISTS'],true))return false;
        throw new RuntimeException('ERP sales return create preflight was not confirmed: '.($status!==''?$status:'UNKNOWN').'.');
    }

    public function probeExisting(string $originalInvoiceId,string $originalInvoiceNumber=''): ?string
    {
        $originalInvoiceId=trim($originalInvoiceId);
        if(preg_match('/^[0-9]+$/D',$originalInvoiceId)!==1)throw new InvalidArgumentException('ERP original sale invoice ID is invalid.');
        $result=$this->run([
            'action'=>'PREFLIGHT','original_invoice_id'=>$originalInvoiceId,
            'original_invoice_number'=>trim($originalInvoiceNumber),
        ]);
        $status=strtoupper(trim((string)($result['status']??'')));
        if($status==='NOT_FOUND')return null;
        $externalId=trim((string)($result['external_id']??''));
        if($status==='FOUND' && preg_match('/^[0-9]+$/D',$externalId)===1)return $externalId;
        throw new RuntimeException('ERP sales return preflight was not confirmed.');
    }

    public function readBack(string $erpSalesReturnId,string $amazonOrderId): ?array
    {
        $erpSalesReturnId=trim($erpSalesReturnId);
        if(preg_match('/^[0-9]+$/D',$erpSalesReturnId)!==1){
            throw new InvalidArgumentException('ERP sales return ID is invalid.');
        }
        $result=$this->run(['action'=>'READBACK','external_id'=>$erpSalesReturnId,'amazon_order_id'=>trim($amazonOrderId)]);
        $status=strtoupper(trim((string)($result['status']??'')));
        if($status==='NOT_FOUND')return null;
        if($status==='FOUND' && is_array($result['record']??null))return $result['record'];
        throw new RuntimeException(
            'ERP sales return read-back was not confirmed: '.($status!==''?$status:'UNKNOWN').'.'
        );
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
