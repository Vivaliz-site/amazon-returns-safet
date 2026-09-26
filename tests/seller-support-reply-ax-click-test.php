<?php
declare(strict_types=1);

function ssaxAssert(bool $ok,string $message):void
{
    if(!$ok)throw new RuntimeException($message);
}

$worker=(string)file_get_contents(__DIR__.'/../scripts/amazon-returns/seller-central-bridge-worker.mjs');
$start=strpos($worker,'  async clickButtonTrustedByText(labels) {');
$end=$start===false?false:strpos($worker,'  async frameButtonReadyByText(label) {',$start);
ssaxAssert($start!==false && $end!==false,'Trusted Seller Support send helper must remain auditable.');
$helper=substr($worker,(int)$start,(int)$end-(int)$start);

ssaxAssert(
    str_contains($helper,"Accessibility.getFullAXTree"),
    'Seller Support final-send lookup must use the browser accessibility tree instead of recursively walking every DOM element.'
);
ssaxAssert(
    str_contains($helper,"Page.getFrameTree"),
    'Seller Support final-send lookup must inspect every CDP frame deterministically.'
);
ssaxAssert(
    str_contains($helper,"backendDOMNodeId") && str_contains($helper,"DOM.resolveNode"),
    'Accessible send candidates must resolve back to DOM nodes before trusted pointer input.'
);
ssaxAssert(
    !str_contains($helper,"querySelectorAll?.('*')"),
    'Final-send lookup must not recursively scan every DOM element; that path can exceed the CDP command timeout on the live case dashboard.'
);
ssaxAssert(
    str_contains($helper,"visible.length===1"),
    'Final-send lookup must remain fail-closed unless exactly one visible enabled candidate exists.'
);

echo "seller-support-reply-ax-click-test: OK\n";
