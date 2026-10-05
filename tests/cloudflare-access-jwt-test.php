<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/CloudflareAccessJwt.php';

function cajAssert(bool $condition,string $message): void
{
    if(!$condition){fwrite(STDERR,$message."\n");exit(1);}
}

$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
cajAssert($key!==false,'Test RSA key generation failed.');
$details=openssl_pkey_get_details($key);
cajAssert(is_array($details) && is_string($details['key']??null),'Test public key missing.');
$public=(string)$details['key'];

function cajB64(string $raw): string
{
    return rtrim(strtr(base64_encode($raw),'+/','-_'),'=');
}
function cajToken($key,array $claims): string
{
    $header=cajB64((string)json_encode(['alg'=>'RS256','typ'=>'JWT','kid'=>'test-key']));
    $payload=cajB64((string)json_encode($claims));
    $input=$header.'.'.$payload;
    $signature='';
    if(!openssl_sign($input,$signature,$key,OPENSSL_ALGO_SHA256)) throw new RuntimeException('sign failed');
    return $input.'.'.cajB64($signature);
}

$now=1791169000;
$team='https://shopvivaliz.cloudflareaccess.com';
$aud='amazon-returns-email-ingress';
$loader=static fn(string $url):array=>[
    'public_certs'=>[$public],
];
$validator=new SvAmazonCloudflareAccessJwt($loader,static fn():int=>$now);
$claims=['iss'=>$team,'aud'=>[$aud],'iat'=>$now-10,'nbf'=>$now-10,'exp'=>$now+300,'sub'=>'service-token'];

$valid=cajToken($key,$claims);
cajAssert($validator->validate($valid,$team,$aud)!==null,'Valid Access JWT must pass.');
cajAssert($validator->validate(cajToken($key,$claims+['aud'=>['wrong']]),$team,$aud)!==null,'Array union sanity failed.');
$wrongAud=$claims;$wrongAud['aud']=['wrong'];
cajAssert($validator->validate(cajToken($key,$wrongAud),$team,$aud)===null,'Wrong audience must fail.');
$wrongIssuer=$claims;$wrongIssuer['iss']='https://other.cloudflareaccess.com';
cajAssert($validator->validate(cajToken($key,$wrongIssuer),$team,$aud)===null,'Wrong issuer must fail.');
$expired=$claims;$expired['exp']=$now-1;
cajAssert($validator->validate(cajToken($key,$expired),$team,$aud)===null,'Expired token must fail.');
[$tamperedHeader,$tamperedPayload,$tamperedSignature]=explode('.',$valid);
$signatureRaw=base64_decode(strtr($tamperedSignature,'-_','+/').str_repeat('=',(4-strlen($tamperedSignature)%4)%4),true);
cajAssert(is_string($signatureRaw) && $signatureRaw!=='','Signature fixture must decode.');
$signatureRaw[0]=chr(ord($signatureRaw[0]) ^ 0x01);
$tampered=$tamperedHeader.'.'.$tamperedPayload.'.'.cajB64($signatureRaw);
cajAssert($validator->validate($tampered,$team,$aud)===null,'Tampered signature must fail.');
cajAssert($validator->validate($valid,'https://evil.example.com',$aud)===null,'Untrusted team domain must fail.');

echo "cloudflare-access-jwt-test: OK\n";
