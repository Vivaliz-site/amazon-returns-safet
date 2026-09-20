<?php
declare(strict_types=1);

$script=(string)file_get_contents(__DIR__.'/../scripts/auto-deploy.sh');
if($script==='')throw new RuntimeException('Root auto-deploy script missing.');

$markers=[
    'systemctl reset-failed amazon-returns-seller-central-browser.service',
    'systemctl start amazon-returns-seller-central-auth-check.service',
    'systemctl start --no-block amazon-returns-seller-central-browser.service',
];
foreach($markers as $marker){
    if(!str_contains($script,$marker)){
        throw new RuntimeException('A successful production auto-deploy must recover and kick the Seller Central browser cycle: '.$marker);
    }
}
if(!str_contains($script,'seller_central_browser_is_running')){
    throw new RuntimeException('Seller Central deploy kick must detect an already-running owned browser cycle.');
}
if(!str_contains($script,"seller_central_browser_deploy_kick=browser_already_running")){
    throw new RuntimeException('Owned browser overlap must be reported as a safe no-op instead of PREEXISTING_CDP_UNOWNED.');
}
$guard=strpos($script,'if seller_central_browser_is_running; then');
$auth=strpos($script,'systemctl start amazon-returns-seller-central-auth-check.service');
if($guard===false || $auth===false || $guard>$auth){
    throw new RuntimeException('Owned browser serialization guard must execute before Seller Central auth-check.');
}

echo "browser-deploy-recovery-contract-test: OK\n";
