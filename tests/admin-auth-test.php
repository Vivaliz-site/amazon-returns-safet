<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/AdminAuth.php';
require_once __DIR__ . '/../includes/Csrf.php';

function aaAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$hash=password_hash('correct horse battery staple',PASSWORD_DEFAULT);
$credentials=['username'=>'fred','password_hash'=>$hash];
aaAssert(SvAmazonReturnsAdminAuth::verifyCredentials('fred','correct horse battery staple',$credentials),'Valid standalone credential must verify.');
aaAssert(!SvAmazonReturnsAdminAuth::verifyCredentials('fred','wrong',$credentials),'Wrong password must fail.');
aaAssert(!SvAmazonReturnsAdminAuth::verifyCredentials('other','correct horse battery staple',$credentials),'Wrong user must fail.');

SvAmazonReturnsAdminAuth::start();
$token=SvAmazonReturnsCsrf::token('intake');
aaAssert(preg_match('/^[a-f0-9]{64}$/',$token)===1,'CSRF token must be random 256-bit hex.');
aaAssert(SvAmazonReturnsCsrf::valid('intake',$token),'Issued CSRF token must validate.');
aaAssert(!SvAmazonReturnsCsrf::valid('intake',str_repeat('0',64)),'Forged CSRF token must fail.');

echo "admin-auth-test: OK\n";
