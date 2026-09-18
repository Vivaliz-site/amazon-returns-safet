<?php
declare(strict_types=1);

require_once __DIR__.'/Config.php';

final class SvAmazonErpInvoiceLookup
{
    /** @var array<string,string> */
    private array $credentials;
    /** @var callable(string,string,array<string,string>,?string):array<string,mixed> */
    private $http;
    /** @var callable():void|null */
    private $beforeRequest;
    private string $apiBase='https://api.tiny.com.br/public-api/v3';

    /** @param array<string,string>|null $credentials */
    public function __construct(?array $credentials=null,?callable $http=null,?SvAmazonReturnsConfig $config=null,?callable $beforeRequest=null)
    {
        $this->credentials=$credentials ?? self::loadCredentials($config ?? new SvAmazonReturnsConfig());
        $this->http=$http ?? [$this,'httpRequest'];
        $this->beforeRequest=$beforeRequest;
    }

    public static function defaultCredentialPath(): string
    {
        return '/home/ubuntu/amazon-returns-deploy/shared/erp.env';
    }

    /** @return array<string,mixed>|null */
    public function findOrderByInvoiceNumber(string $invoiceNumber): ?array
    {
        $invoiceNumber=trim($invoiceNumber);
        if(preg_match('/^[0-9]{1,20}$/D',$invoiceNumber)!==1){
            throw new InvalidArgumentException('ERP invoice number is invalid.');
        }
        $query=http_build_query([
            'tipo'=>'S',
            'numero'=>$invoiceNumber,
            'limit'=>100,
            'offset'=>0,
        ],'','&',PHP_QUERY_RFC3986);
        $rows=$this->listInvoices($query,'ERP invoice lookup');
        $matches=[];
        foreach($rows as $row){
            if(strtoupper(trim((string)($row['tipo'] ?? 'S')))!=='S')continue;
            $number=trim((string)($row['numero'] ?? ''));
            if(self::canonicalNumber($number)!==self::canonicalNumber($invoiceNumber))continue;
            $ecommerce=is_array($row['ecommerce'] ?? null)?$row['ecommerce']:[];
            $orderId=trim((string)($ecommerce['numeroPedidoEcommerce'] ?? ''));
            if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$orderId)!==1)continue;
            $matches[$orderId][]=$row;
        }
        if($matches===[])return null;
        if(count($matches)!==1)throw new UnexpectedValueException('ERP invoice maps to multiple Amazon orders.');
        $orderId=(string)array_key_first($matches);
        return $this->saleProjection($matches[$orderId][0],$orderId,$invoiceNumber);
    }

    /** @return array<string,mixed>|null */
    public function findSaleForOrder(string $amazonOrderId): ?array
    {
        $amazonOrderId=trim($amazonOrderId);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$amazonOrderId)!==1){
            throw new InvalidArgumentException('Amazon order ID is invalid.');
        }
        $query=http_build_query([
            'tipo'=>'S',
            'numeroPedidoEcommerce'=>$amazonOrderId,
            'limit'=>100,
            'offset'=>0,
        ],'','&',PHP_QUERY_RFC3986);
        $rows=$this->listInvoices($query,'ERP sale lookup');
        $matches=[];
        foreach($rows as $row){
            if(strtoupper(trim((string)($row['tipo'] ?? 'S')))!=='S')continue;
            $ecommerce=is_array($row['ecommerce'] ?? null)?$row['ecommerce']:[];
            if(trim((string)($ecommerce['numeroPedidoEcommerce'] ?? ''))!==$amazonOrderId)continue;
            $id=trim((string)($row['id'] ?? ''));
            if($id==='')continue;
            $matches[$id]=$row;
        }
        if($matches!==[]){
            if(count($matches)!==1)throw new UnexpectedValueException('Amazon order maps to multiple ERP sale invoices.');
            return $this->saleProjection(array_values($matches)[0],$amazonOrderId,null);
        }
        return $this->findSaleViaSalesOrder($amazonOrderId);
    }

    /** @return array<string,mixed>|null */
    private function findSaleViaSalesOrder(string $amazonOrderId): ?array
    {
        $query=http_build_query([
            'numeroPedidoEcommerce'=>$amazonOrderId,
            'origemPedido'=>0,
            'limit'=>100,
            'offset'=>0,
        ],'','&',PHP_QUERY_RFC3986);
        $list=$this->requestJson('GET',$this->apiBase.'/pedidos?'.$query,'ERP sales order lookup');
        $rows=is_array($list['itens']??null)?$list['itens']:[];
        $matches=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            $ecommerce=is_array($row['ecommerce']??null)?$row['ecommerce']:[];
            if(trim((string)($ecommerce['numeroPedidoEcommerce']??''))!==$amazonOrderId)continue;
            $id=trim((string)($row['id']??''));
            if(preg_match('/^[0-9]+$/D',$id)!==1)continue;
            $matches[$id]=true;
        }
        if($matches===[])return null;
        if(count($matches)!==1)throw new UnexpectedValueException('Amazon order maps to multiple ERP sales orders.');
        $orderId=(string)array_key_first($matches);
        $detail=$this->requestJson('GET',$this->apiBase.'/pedidos/'.rawurlencode($orderId),'ERP sales order detail lookup');
        $ecommerce=is_array($detail['ecommerce']??null)?$detail['ecommerce']:[];
        if(trim((string)($ecommerce['numeroPedidoEcommerce']??''))!==$amazonOrderId){
            throw new UnexpectedValueException('ERP sales order detail does not match Amazon order.');
        }
        $invoiceId=trim((string)($detail['idNotaFiscal']??''));
        if(preg_match('/^[0-9]+$/D',$invoiceId)!==1)return null;
        $invoice=$this->requestJson('GET',$this->apiBase.'/notas/'.rawurlencode($invoiceId),'ERP sale invoice detail lookup');
        if(trim((string)($invoice['id']??''))!==$invoiceId){
            throw new UnexpectedValueException('ERP sale invoice detail ID mismatch.');
        }
        if(strtoupper(trim((string)($invoice['tipo']??'S')))!=='S')return null;
        return $this->saleProjection($invoice,$amazonOrderId,null);
    }

    /** @return list<array<string,mixed>> */
    private function listInvoices(string $query,string $label): array
    {
        $json=$this->requestJson('GET',$this->apiBase.'/notas?'.$query,$label);
        $rows=is_array($json['itens'] ?? null)?$json['itens']:[];
        return array_values(array_filter($rows,'is_array'));
    }

    /** @return array<string,mixed> */
    private function requestJson(string $method,string $url,string $label): array
    {
        if(is_callable($this->beforeRequest))($this->beforeRequest)();
        $response=($this->http)(
            $method,$url,
            ['Authorization'=>'Bearer '.$this->accessToken(),'Accept'=>'application/json'],null
        );
        $status=(int)($response['status']??0);
        if($status!==200)throw new RuntimeException($label.' failed with HTTP '.$status.'.');
        return is_array($response['json']??null)?$response['json']:[];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function saleProjection(array $row,string $orderId,?string $fallbackNumber): array
    {
        $ecommerce=is_array($row['ecommerce'] ?? null)?$row['ecommerce']:[];
        return [
            'source'=>'ERP_OLIST_INVOICE',
            'request_id'=>null,
            'invoice_id'=>trim((string)($row['id'] ?? '')),
            'invoice_number'=>trim((string)($row['numero'] ?? ($fallbackNumber ?? ''))),
            'series'=>self::nullable($row['serie'] ?? null),
            'access_key'=>self::nullable($row['chaveAcesso'] ?? $row['chave_acesso'] ?? null),
            'status'=>self::nullable($row['situacao'] ?? null),
            'invoice_type'=>self::nullable($row['tipo'] ?? null),
            'transaction_type'=>'ERP_SALE',
            'order_id'=>$orderId,
            'sales_channel'=>self::nullable($ecommerce['nome'] ?? null),
        ];
    }

    private function accessToken(): string
    {
        foreach(['TINY_ACCESS_TOKEN','OLIST_ACCESS_TOKEN'] as $key){
            $value=trim((string)($this->credentials[$key] ?? ''));
            if($value!=='')return $value;
        }
        throw new RuntimeException('ERP access token is not configured.');
    }

    /** @return array<string,string> */
    private static function loadCredentials(SvAmazonReturnsConfig $config): array
    {
        $path=$config->get('AMAZON_RETURNS_ERP_ENV_FILE',self::defaultCredentialPath());
        if($path==='' || !is_readable($path))throw new RuntimeException('ERP credential source is not configured.');
        $wanted=array_fill_keys([
            'TINY_ACCESS_TOKEN','OLIST_ACCESS_TOKEN',
            'TINY_CLIENT_ID','TINY_CLIENT_SECRET','TINY_REFRESH_TOKEN',
            'OLIST_CLIENT_ID','OLIST_CLIENT_SECRET','OLIST_REFRESH_TOKEN',
        ],true);
        $values=[];
        foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
            $line=trim($line);
            if($line==='' || str_starts_with($line,'#') || !str_contains($line,'='))continue;
            [$key,$value]=array_map('trim',explode('=',$line,2));
            if(!isset($wanted[$key]))continue;
            if(strlen($value)>=2 && (($value[0]==='"' && $value[-1]==='"') || ($value[0]==="'" && $value[-1]==="'"))){
                $value=substr($value,1,-1);
            }
            $values[$key]=$value;
        }
        return $values;
    }

    private static function canonicalNumber(string $value): string
    {
        $value=ltrim(trim($value),'0');
        return $value===''?'0':$value;
    }

    private static function nullable(mixed $value): ?string
    {
        if(!is_scalar($value))return null;
        $value=trim((string)$value);
        return $value===''?null:$value;
    }

    /** @return array{status:int,json:array<string,mixed>} */
    private function httpRequest(string $method,string $url,array $headers,?string $body): array
    {
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Unable to initialize ERP HTTP request.');
        $headerLines=[];
        foreach($headers as $name=>$value)$headerLines[]=$name.': '.$value;
        curl_setopt_array($ch,[
            CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_HTTPHEADER=>$headerLines,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
        ]);
        if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
        $raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $error=curl_error($ch);
        curl_close($ch);
        if(!is_string($raw))throw new RuntimeException('ERP HTTP transport failed: '.$error);
        $json=json_decode($raw,true);
        return ['status'=>$status,'json'=>is_array($json)?$json:[]];
    }
}
