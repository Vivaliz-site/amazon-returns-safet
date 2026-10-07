<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/amazon-returns/RemoteBridge.php';

function sssuAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$baseRow=['kind'=>'SELLER_SUPPORT_UPDATE','payload'=>['support_case_id'=>'22403466041']];

sssuAssert(
    SvAmazonReturnsRemoteBridge::staleScopedSupportUpdate($baseRow,['support_case_id'=>'22403824741']),
    'A scoped support update must be stale after the case binding moves to another support case.'
);
sssuAssert(
    !SvAmazonReturnsRemoteBridge::staleScopedSupportUpdate($baseRow,['support_case_id'=>'22403466041']),
    'Matching support bindings must remain executable.'
);
sssuAssert(
    !SvAmazonReturnsRemoteBridge::staleScopedSupportUpdate(
        ['kind'=>'SELLER_SUPPORT_OPEN','payload'=>['support_case_id'=>'22403466041']],
        ['support_case_id'=>'22403824741']
    ),
    'Only support updates may be superseded by a binding change.'
);
sssuAssert(
    !SvAmazonReturnsRemoteBridge::staleScopedSupportUpdate($baseRow,['support_case_id'=>null]),
    'A missing current binding must not silently discard a scoped update.'
);

$service=(string)file_get_contents(__DIR__.'/../includes/amazon-returns/BridgeService.php');
$guard=strpos($service,'SvAmazonReturnsRemoteBridge::staleScopedSupportUpdate($row,$case)');
$envelope=strpos($service,'SvAmazonReturnsRemoteBridge::jobEnvelope($row,$case,$flags)');
sssuAssert(
    $guard!==false && $envelope!==false && $guard<$envelope,
    'Stale support binding reconciliation must run before the browser job envelope is emitted.'
);
sssuAssert(
    str_contains($service,"'SELLER_SUPPORT_SCOPE_SUPERSEDED'"),
    'Stale support update supersession must be observable.'
);

echo "seller-support-stale-scoped-update-test: OK\n";
