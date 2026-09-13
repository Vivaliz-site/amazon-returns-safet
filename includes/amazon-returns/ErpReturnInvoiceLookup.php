<?php
declare(strict_types=1);

require_once __DIR__.'/Config.php';

final class SvAmazonErpReturnInvoiceLookup
{
    /** @var array<string,string> */
    private array $credentials;
    /** @var callable(string,string,array<string,string>,?string):array<string,mixed> */
    private $http;
    private string $apiBase='https://api.tiny.com.br/public-api/v3';

    /** @param array<string,string>|null $credentials */
    public function __construct(?array $credentials=null,?callable $http=null,?SvAmazonReturnsConfig $config=null)
    {
        $this->credentials=$credentials ?? self::loadCredentials($config ?? new SvAmazonReturnsConfig());
        $this->http=$http ?? [$this,'httpRequest'];
    }

    public static function defaultCredentialPath(): string
    {
        return '/home/ubuntu/amazon-returns-deploy/shared/erp.env';
    }

    /** @return array<string,mixed>|null */
    public function findForOrder(string $amazonOrderId): ?array
    {
        $amazonOrderId=trim($amazonOrderId);
        if(preg_match('/^[0-9]{3}-[0-9]{7}-[0-9]{7}$/D',$amazonOrderId)!==1){
            throw new InvalidArgumentException('Amazon order ID is invalid.');
        }
        $query=http_build_query([
            'tipo'=>'E',
            'numeroPedidoEcommerce'=>$amazonOrderId,
            'limit'=>100,
            'offset'=>0,
        ],'','&',PHP_QUERY_RFC3986);
        $response=($this->http)(
            'GET',$this->apiBase.'/notas?'.$query,
            ['Authorization'=>'Bearer '.$this->accessToken(),'Accept'=>'application/json'],null
        );
        $status=(int)($response['status']??0);
        if($status!==200)throw new RuntimeException('ERP return invoice lookup failed with HTTP '.$status.'.');
        $json=is_array($response['json']??null)?$response['json']:[];
        $rows=is_array($json['itens']??null)?$json['itens']:[];
        $matches=[];
        foreach($rows as $row){
            if(!is_array($row))continue;
            if(strtoupper(trim((string)($row['tipo']??'')))!=='E')continue;
            $ecommerce=is_array($row['ecommerce']??null)?$row['ecommerce']:[];
            if(trim((string)($ecommerce['numeroPedidoEcommerce']??''))!==$amazonOrderId)continue;
            $id=trim((string)($row['id']??''));
            if($id==='' || preg_match('/^[0-9]+$/D',$id)!==1)continue;
            $detailResponse=($this->http)(
                'GET',$this->apiBase.'/notas/'.rawurlencode($id),
                ['Authorization'=>'Bearer '.$this->accessToken(),'Accept'=>'application/json'],null
            );
            $detailStatus=(int)($detailResponse['status']??0);
            if($detailStatus!==200)throw new RuntimeException('ERP return invoice detail lookup failed with HTTP '.$detailStatus.'.');
            $detail=is_array($detailResponse['json']??null)?$detailResponse['json']:[];
            if(strtoupper(trim((string)($detail['tipo']??'')))!=='E')continue;
            $detailEcommerce=is_array($detail['ecommerce']??null)?$detail['ecommerce']:[];
            if(trim((string)($detailEcommerce['numeroPedidoEcommerce']??''))!==$amazonOrderId)continue;
            $purpose=(int)($detail['finalidade']??0);
            if($purpose!==4)continue;
            $matches[]=[
                'source'=>'ERP_OLIST_RETURN_INVOICE',
                'invoice_id'=>(string)($detail['id']??$id),
                'invoice_number'=>self::nullable($detail['numero']??($row['numero']??null)),
                'series'=>self::nullable($detail['serie']??($row['serie']??null)),
                'access_key'=>self::nullable($detail['chaveAcesso']??($row['chaveAcesso']??null)),
                'status'=>self::nullable($detail['situacao']??($row['situacao']??null)),
                'purpose'=>$purpose,
                'order_id'=>$amazonOrderId,
                'issued_at'=>self::nullable($detail['dataEmissao']??($row['dataEmissao']??null)),
            ];
        }
        if($matches===[])return null;
        if(count($matches)!==1)throw new UnexpectedValueException('ERP order maps to multiple return invoices.');
        return $matches[0];
    }

    private function accessToken(): string
    {
        foreach(['TINY_ACCESS_TOKEN','OLIST_ACCESS_TOKEN'] as $key){
            $value=trim((string)($this->credentials[$key]??''));
            if($value!=='')return $value;
        }
        throw new RuntimeException('ERP access token is not configured.');
    }

    /** @return array<string,string> */
    private static function loadCredentials(SvAmazonReturnsConfig $config): array
    {
        $path=$config->get('AMAZON_RETURNS_ERP_ENV_FILE',self::defaultCredentialPath());
        if($path==='' || !is_readable($path))throw new RuntimeException('ERP credential source is not configured.');
        $wanted=array_fill_keys(['TINY_ACCESS_TOKEN','OLIST_ACCESS_TOKEN'],true);
        $values=[];
        foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
            $line=trim($line);
            if($line==='' || str_starts_with($line,'#') || !str_contains($line,'='))continue;
            [$key,$value]=array_map('trim',explode('=',$line,2));
            if(!isset($wanted[$key]))continue;
            if(strlen($value)>=2 && (($value[0]==='"' && $value[-1]==='"') || ($value[0]==="'" && $value[-1]==="'")))$value=substr($value,1,-1);
            $values[$key]=$value;
        }
        return $values;
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
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headerLines,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
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
