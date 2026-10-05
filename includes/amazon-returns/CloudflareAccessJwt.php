<?php
declare(strict_types=1);

final class SvAmazonCloudflareAccessJwt
{
    private $certLoader;
    private $now;

    public function __construct(?callable $certLoader=null,?callable $now=null)
    {
        $this->certLoader=$certLoader ?? [$this,'loadCerts'];
        $this->now=$now ?? static fn():int=>time();
    }

    public function validate(string $jwt,string $teamDomain,string $audience): ?array
    {
        $jwt=trim($jwt);
        $teamDomain=rtrim(trim($teamDomain),'/');
        $audience=trim($audience);
        if($jwt==='' || $audience==='' || !$this->trustedTeamDomain($teamDomain))return null;

        $parts=explode('.',$jwt);
        if(count($parts)!==3)return null;
        [$encodedHeader,$encodedPayload,$encodedSignature]=$parts;
        $header=$this->decodeJson($encodedHeader);
        $claims=$this->decodeJson($encodedPayload);
        $signature=$this->decodeBase64Url($encodedSignature);
        if($header===null || $claims===null || $signature===null)return null;
        if(strtoupper(trim((string)($header['alg'] ?? '')))!=='RS256')return null;

        $now=($this->now)();
        $exp=filter_var($claims['exp'] ?? null,FILTER_VALIDATE_INT);
        $nbf=filter_var($claims['nbf'] ?? null,FILTER_VALIDATE_INT);
        $iat=filter_var($claims['iat'] ?? null,FILTER_VALIDATE_INT);
        if($exp===false || $exp<$now)return null;
        if($nbf!==false && $nbf>$now+60)return null;
        if($iat!==false && $iat>$now+60)return null;
        if(rtrim(trim((string)($claims['iss'] ?? '')),'/')!==$teamDomain)return null;
        if(!$this->audienceMatches($claims['aud'] ?? null,$audience))return null;

        try{
            $certs=($this->certLoader)($teamDomain.'/cdn-cgi/access/certs');
        }catch(Throwable){
            return null;
        }
        $publicCerts=is_array($certs['public_certs'] ?? null)?$certs['public_certs']:[];
        if($publicCerts===[])return null;

        $signed=$encodedHeader.'.'.$encodedPayload;
        foreach($publicCerts as $pem){
            if(!is_string($pem) || trim($pem)==='')continue;
            $key=openssl_pkey_get_public($pem);
            if($key===false)continue;
            if(openssl_verify($signed,$signature,$key,OPENSSL_ALGO_SHA256)===1)return $claims;
        }
        return null;
    }

    private function trustedTeamDomain(string $domain): bool
    {
        return preg_match('#^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.cloudflareaccess\.com$#D',$domain)===1;
    }

    private function audienceMatches(mixed $claim,string $expected): bool
    {
        if(is_string($claim))return hash_equals($expected,$claim);
        if(!is_array($claim))return false;
        foreach($claim as $value){
            if(is_string($value) && hash_equals($expected,$value))return true;
        }
        return false;
    }

    private function decodeJson(string $encoded): ?array
    {
        $raw=$this->decodeBase64Url($encoded);
        if($raw===null)return null;
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:null;
    }

    private function decodeBase64Url(string $encoded): ?string
    {
        if($encoded==='' || preg_match('/^[A-Za-z0-9_-]+$/D',$encoded)!==1)return null;
        $raw=strtr($encoded,'-_','+/');
        $remainder=strlen($raw)%4;
        if($remainder!==0)$raw.=str_repeat('=',4-$remainder);
        $decoded=base64_decode($raw,true);
        return is_string($decoded)?$decoded:null;
    }

    private function loadCerts(string $url): array
    {
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Cloudflare Access cert transport unavailable.');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_TIMEOUT=>10,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Accept: application/json'],
        ]);
        $raw=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if(!is_string($raw) || $status<200 || $status>=300){
            throw new RuntimeException('Cloudflare Access cert fetch failed.');
        }
        $decoded=json_decode($raw,true);
        if(!is_array($decoded))throw new RuntimeException('Cloudflare Access cert response invalid.');
        return $decoded;
    }
}
