<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/AdminAuth.php';
require_once __DIR__ . '/../includes/Csrf.php';

function aaAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$hash=password_hash('correct horse battery staple',PASSWORD_DEFAULT);
$credentials=['username'=>'fred','password_hash'=>$hash];
aaAssert(SvAmazonReturnsAdminAuth::verifyCredentials('fred','correct horse battery staple',$credentials),'Valid standalone credential must verify.');
aaAssert(SvAmazonReturnsAdminAuth::verifyCredentials('Fred','correct horse battery staple',$credentials),'Username matching must be case-insensitive.');
aaAssert(!SvAmazonReturnsAdminAuth::verifyCredentials('fred','wrong',$credentials),'Wrong password must fail.');
aaAssert(!SvAmazonReturnsAdminAuth::verifyCredentials('other','correct horse battery staple',$credentials),'Wrong user must fail.');

SvAmazonReturnsAdminAuth::start();
$_SESSION['amazon_returns_admin']=['username'=>'fred','authenticated_at'=>time()-43201,'last_seen_at'=>time()-43201];
aaAssert(!SvAmazonReturnsAdminAuth::loggedIn(),'Admin sessions older than the absolute lifetime must expire.');
$_SESSION['amazon_returns_admin']=['username'=>'fred','authenticated_at'=>time()-60,'last_seen_at'=>time()-3601];
aaAssert(!SvAmazonReturnsAdminAuth::loggedIn(),'Idle admin sessions must expire.');
$_SESSION['amazon_returns_admin']=['username'=>'fred','authenticated_at'=>time()-60,'last_seen_at'=>time()-60];
aaAssert(SvAmazonReturnsAdminAuth::loggedIn(),'Fresh active admin session must remain valid.');

$token=SvAmazonReturnsCsrf::token('intake');
aaAssert(preg_match('/^[a-f0-9]{64}$/',$token)===1,'CSRF token must be random 256-bit hex.');
aaAssert(SvAmazonReturnsCsrf::valid('intake',$token),'Issued CSRF token must validate.');
aaAssert(!SvAmazonReturnsCsrf::valid('intake',str_repeat('0',64)),'Forged CSRF token must fail.');

$loginSource=(string)file_get_contents(__DIR__.'/../login.php');
aaAssert(str_contains($loginSource,'return_to'),'Login must preserve a safe return path after session expiry.');
aaAssert(str_contains($loginSource,'for="username"') && str_contains($loginSource,'for="password"'),'Login labels must be bound to inputs.');
echo "admin-auth-test: OK\n";
