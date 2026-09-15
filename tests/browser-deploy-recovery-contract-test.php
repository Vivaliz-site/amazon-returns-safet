<?php
declare(strict_types=1);

$script=(string)file_get_contents(__DIR__.'/../scripts/provision-production.sh');
if($script==='')throw new RuntimeException('Production provision script missing.');

$markers=[
    'systemctl reset-failed amazon-returns-seller-central-browser.service',
    'systemctl start amazon-returns-seller-central-auth-check.service',
    'systemctl start --no-block amazon-returns-seller-central-browser.service',
];
foreach($markers as $marker){
    if(!str_contains($script,$marker)){
        throw new RuntimeException('A successful production deploy must recover and kick the Seller Central browser cycle: '.$marker);
    }
}

echo "browser-deploy-recovery-contract-test: OK\n";
