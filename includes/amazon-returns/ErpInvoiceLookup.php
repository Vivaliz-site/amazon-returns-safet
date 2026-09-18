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
    /** @var array<string,list<array<string,mixed>>> */
    private array $invoiceDateCache=[];
    /** @var array<string,array<string,mixed>> */
    private array $invoiceDetailCache=[];
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
    public function findSaleViaSalesOrder(string $amazonOrderId): ?array
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

    /**
     * Recovers historical ERP sale invoices whose marketplace linkage was lost.
     * The match is intentionally strict: exact issue date, exact gross sale amount,
     * exact SKU/quantity multiset, authorized normal outgoing invoice, and a single
     * orphan candidate only. Ambiguous or incomplete evidence returns null.
     *
     * @param array<string,int> $expectedItems normalized or raw SKU=>quantity map
     * @return array<string,mixed>|null
     */
    public function findOrphanSaleForOrder(
        string $amazonOrderId,
        string $orderDate,
        string $salesAmount,
        array $expectedItems
    ): ?array {
        $amazonOrderId=trim($amazonOrderId);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$amazonOrderId)!==1){
            throw new InvalidArgumentException('Amazon order ID is invalid.');
        }
        $orderDate=self::dateKey($orderDate);
        $money=self::moneyKey($salesAmount);
        if($money===null || (float)$money<=0){
            throw new InvalidArgumentException('ERP orphan recovery sale amount is invalid.');
        }
        $expected=self::normalizeItemMap($expectedItems);
        if($expected===[]){
            throw new InvalidArgumentException('ERP orphan recovery item signature is empty.');
        }

        $candidateIds=[];
        foreach($this->invoicesForDate($orderDate) as $row){
            if(strtoupper(trim((string)($row['tipo']??'')))!=='S')continue;
            if(trim((string)($row['situacao']??''))!=='6')continue;
            if(self::moneyKey($row['valor']??null)!==$money)continue;
            $ecommerce=is_array($row['ecommerce']??null)?$row['ecommerce']:[];
            if(!self::isOrphanEcommerce($ecommerce))continue;
            $id=trim((string)($row['id']??''));
            if(preg_match('/^[0-9]+$/D',$id)!==1)continue;
            $candidateIds[$id]=true;
        }
        if($candidateIds===[])return null;

        $matches=[];
        foreach(array_keys($candidateIds) as $invoiceId){
            $invoiceId=(string)$invoiceId;
            $detail=$this->invoiceDetail($invoiceId);
            if(trim((string)($detail['id']??''))!==$invoiceId)continue;
            if(strtoupper(trim((string)($detail['tipo']??'')))!=='S')continue;
            if(trim((string)($detail['situacao']??''))!=='6')continue;
            if(trim((string)($detail['finalidade']??''))!=='1')continue;
            if(trim((string)($detail['dataEmissao']??''))!==$orderDate)continue;
            if(self::moneyKey($detail['valor']??null)!==$money)continue;
            $ecommerce=is_array($detail['ecommerce']??null)?$detail['ecommerce']:[];
            if(!self::isOrphanEcommerce($ecommerce))continue;
            $items=is_array($detail['itens']??null)?$detail['itens']:[];
            if(self::invoiceItemMap($items)!==$expected)continue;
            $matches[$invoiceId]=$detail;
        }
        if(count($matches)!==1)return null;
        $sale=$this->saleProjection(array_values($matches)[0],$amazonOrderId,null);
        $sale['match_method']='ORPHAN_INVOICE_EXACT_DATE_AMOUNT_ITEMS';
        return $sale;
    }

    /** @return list<array<string,mixed>> */
    private function invoicesForDate(string $date): array
    {
        if(isset($this->invoiceDateCache[$date]))return $this->invoiceDateCache[$date];
        $all=[];$offset=0;$limit=100;$pages=0;
        do{
            $query=http_build_query([
                'tipo'=>'S','dataInicial'=>$date,'dataFinal'=>$date,
                'limit'=>$limit,'offset'=>$offset,
            ],'','&',PHP_QUERY_RFC3986);
            $json=$this->requestJson('GET',$this->apiBase.'/notas?'.$query,'ERP orphan invoice list lookup');
            $rows=is_array($json['itens']??null)?array_values(array_filter($json['itens'],'is_array')):[];
            foreach($rows as $row){
                $id=trim((string)($row['id']??''));
                if($id!=='')$all[$id]=$row;
            }
            $total=max(0,(int)($json['paginacao']['total']??count($all)));
            $offset+=$limit;$pages++;
            if($pages>=10 && $offset<$total){
                throw new RuntimeException('ERP orphan invoice pagination exceeded safety limit.');
            }
        }while(count($rows)===$limit && $offset<$total);
        return $this->invoiceDateCache[$date]=array_values($all);
    }

    /** @return array<string,mixed> */
    private function invoiceDetail(string $invoiceId): array
    {
        if(isset($this->invoiceDetailCache[$invoiceId]))return $this->invoiceDetailCache[$invoiceId];
        return $this->invoiceDetailCache[$invoiceId]=$this->requestJson(
            'GET',$this->apiBase.'/notas/'.rawurlencode($invoiceId),'ERP orphan invoice detail lookup'
        );
    }

    /** @param array<string,mixed> $ecommerce */
    private static function isOrphanEcommerce(array $ecommerce): bool
    {
        $id=(int)($ecommerce['id']??0);
        $order=trim((string)($ecommerce['numeroPedidoEcommerce']??''));
        $channelOrder=trim((string)($ecommerce['numeroPedidoCanalVenda']??''));
        return $id===0 && $order==='' && $channelOrder==='';
    }

    /** @param array<string,int> $items @return array<string,int> */
    private static function normalizeItemMap(array $items): array
    {
        $normalized=[];
        foreach($items as $sku=>$quantity){
            $key=strtolower(trim((string)$sku));
            $qty=(int)$quantity;
            if($key==='' || $qty<1)continue;
            $normalized[$key]=($normalized[$key]??0)+$qty;
        }
        ksort($normalized,SORT_STRING);
        return $normalized;
    }

    /** @param list<array<string,mixed>> $items @return array<string,int> */
    private static function invoiceItemMap(array $items): array
    {
        $raw=[];
        foreach($items as $item){
            if(!is_array($item))continue;
            $product=is_array($item['produto']??null)?$item['produto']:[];
            $sku=trim((string)($item['codigo']??$item['sku']??$product['codigo']??$product['sku']??''));
            $quantity=$item['quantidade']??null;
            if($sku==='' || !is_numeric($quantity))continue;
            $qty=(int)$quantity;
            if($qty<1 || abs((float)$quantity-$qty)>0.000001)continue;
            $raw[$sku]=($raw[$sku]??0)+$qty;
        }
        return self::normalizeItemMap($raw);
    }

    private static function moneyKey(mixed $value): ?string
    {
        if(!is_scalar($value) || !is_numeric((string)$value))return null;
        $amount=(float)$value;
        if(!is_finite($amount))return null;
        return number_format($amount,2,'.','');
    }

    private static function dateKey(string $value): string
    {
        $value=trim($value);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if(!$date || (is_array($errors) && (($errors['warning_count']??0)>0 || ($errors['error_count']??0)>0))
            || $date->format('Y-m-d')!==$value){
            throw new InvalidArgumentException('ERP orphan recovery order date is invalid.');
        }
        return $value;
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
