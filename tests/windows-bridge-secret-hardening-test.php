<?php
declare(strict_types=1);
$installer=(string)file_get_contents(__DIR__.'/../scripts/install-amazon-returns-windows-bridge.ps1');
function wbshAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
wbshAssert(str_contains($installer,'function Protect-SecretReadFile'),'Installer must centralize restrictive ACL hardening.');
foreach(['$token','$UsernameFile','$PasswordFile','$TotpKeyFile','$TotpKnownHostsFile'] as $path){
    wbshAssert(str_contains($installer,'Protect-SecretReadFile '.$path),'Installer must harden '.$path.'.');
}
wbshAssert(str_contains($installer,'function Escape-PowerShellSingleQuoted'),'Runner values must be escaped before interpolation.');
foreach(['BridgeEndpoint','StatusBridgeEndpoint','UsernameFile','PasswordFile','TotpHost','TotpKeyFile','TotpKnownHostsFile'] as $name){
    wbshAssert(str_contains($installer,'$safe'.$name),'Runner must use an escaped '.$name.' value.');
}
$runnerStart=strpos($installer,'$runnerBody = @"');
$runnerEnd=strpos($installer,'[IO.File]::WriteAllText',$runnerStart);
$runnerSource=substr($installer,$runnerStart,$runnerEnd-$runnerStart);
wbshAssert(!str_contains($runnerSource,'user-data-dir=$profile'),'Generated runner must not interpolate the raw profile path.');
echo "windows-bridge-secret-hardening-test: OK\n";
