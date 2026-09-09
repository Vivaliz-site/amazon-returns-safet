<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$provision=(string)file_get_contents($root.'/scripts/provision-seller-central-browser-host.sh');
$auth=(string)file_get_contents($root.'/scripts/amazon-returns/seller-central-auth.mjs');
if(!str_contains($provision,'SELLER_CENTRAL_MARKETPLACE_LABEL=Brazil')){
    throw new RuntimeException('Primary browser host must configure the Brazil marketplace label explicitly.');
}
if(!str_contains($auth,'SELLER_CENTRAL_MARKETPLACE_LABEL')){
    throw new RuntimeException('Authentication helper must consume the configured marketplace label.');
}
echo "seller-central-marketplace-config-test: OK\n";
