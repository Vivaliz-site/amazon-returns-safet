<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';
$errors=[];
try{$r=SvAmazonReturnsRemoteBridge::validateResult(['status'=>'SUPERSEDED','submitted'=>false,'reason'=>'SAFE_T_WINDOW_EXPIRED']);if(($r['status']??'')!=='SUPERSEDED')$errors[]='SUPERSEDED result must be accepted by bridge contract.';}catch(Throwable $e){$errors[]='SUPERSEDED result rejected: '.$e->getMessage();}
$svc=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
if(!str_contains($svc,"['ACCEPTED','ALREADY_EXISTS','SUPERSEDED']"))$errors[]='BridgeService must complete SUPERSEDED jobs without retry/dead-letter.';
$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
if(!str_contains($worker,'SAFE_T_WINDOW_EXPIRED'))$errors[]='Worker must classify explicit 75-day SAFE-T expiry.';
if(!str_contains($worker,"bridgeResult('SUPERSEDED'"))$errors[]='Expired SAFE-T job must return SUPERSEDED.';
if($errors){fwrite(STDERR,implode("\n",$errors)."\n");exit(1);}echo "safet-expired-worker-fallback-test: OK\n";