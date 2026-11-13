<?php
declare(strict_types=1);
// Leituras pesadas do cockpit não podem segurar a trava da sessão PHP:
// com a trava presa, recarga e navegação ficam na fila atrás delas.
$root=dirname(__DIR__);
function rslAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$auth=(string)file_get_contents($root.'/includes/AdminAuth.php');
rslAssert(str_contains($auth,'public static function releaseSessionLock(): void'),'AdminAuth must expose releaseSessionLock().');
rslAssert(str_contains($auth,'session_write_close()'),'releaseSessionLock must close the session for writing.');
foreach(['cases','case','summary','reviews','rules'] as $endpoint){
    $src=(string)file_get_contents($root.'/admin/amazon-returns/api/'.$endpoint.'.php');
    $login=strpos($src,'SvAmazonReturnsAdminAuth::requireLogin(true);');
    $release=strpos($src,'SvAmazonReturnsAdminAuth::releaseSessionLock();');
    rslAssert($login!==false&&$release!==false&&$release>$login,$endpoint.'.php must release the session lock right after authentication.');
    rslAssert(!str_contains($src,'SvAmazonReturnsCsrf::'),$endpoint.'.php is read-only and must not depend on session writes.');
}
// Endpoints que gravam na sessão (CSRF) continuam com a sessão aberta.
foreach(['case-admin-action','intake','review-decision'] as $endpoint){
    $src=(string)file_get_contents($root.'/admin/amazon-returns/api/'.$endpoint.'.php');
    rslAssert(!str_contains($src,'releaseSessionLock'),$endpoint.'.php validates CSRF and must keep the session.');
}
// Comportamento: depois de liberar, o usuário continua identificável e a sessão não é reaberta.
require_once $root.'/includes/AdminAuth.php';
$_SESSION=['amazon_returns_admin'=>['username'=>'Fred']];
SvAmazonReturnsAdminAuth::releaseSessionLock();
rslAssert(SvAmazonReturnsAdminAuth::username()==='Fred','username() must stay readable after the lock is released.');
rslAssert(session_status()!==PHP_SESSION_ACTIVE,'session must not be reopened after release.');
echo "cockpit-read-endpoints-release-session-lock-test OK\n";
