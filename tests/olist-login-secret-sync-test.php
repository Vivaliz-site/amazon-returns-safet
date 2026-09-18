<?php
declare(strict_types=1);

function olssAssert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}

$root=dirname(__DIR__);
$validator=$root.'/scripts/validate-olist-erp-login-env.py';
$sync=$root.'/scripts/sync-olist-erp-login-env.sh';
$request=$root.'/scripts/request-olist-erp-login-sync.sh';
$workflow=$root.'/.github/workflows/olist-login-envelope.yml';
$auto=$root.'/scripts/auto-deploy.sh';
$provision=$root.'/scripts/provision-olist-erp-browser-host.sh';

foreach([$validator,$sync,$request,$workflow,$auto,$provision] as $path){
    olssAssert(is_file($path),'Missing Olist login sync component: '.basename($path));
}

$syncSource=(string)file_get_contents($sync);
$requestSource=(string)file_get_contents($request);
$workflowSource=(string)file_get_contents($workflow);
$autoSource=(string)file_get_contents($auto);
$provisionSource=(string)file_get_contents($provision);

olssAssert(str_contains($syncSource,'INBOX_DIR="/home/ubuntu/.amazon-returns-secrets-inbox"') && str_contains($syncSource,'INBOX_FILE="$INBOX_DIR/olist-erp-browser-login.env"'),'Sync must consume only the private ubuntu inbox.');
olssAssert(str_contains($syncSource,'invalid_inbox_owner') && str_contains($syncSource,'invalid_inbox_mode'),'Sync must validate the private inbox directory.');
olssAssert(str_contains($syncSource,"stat -c '%U'") && str_contains($syncSource,'== "ubuntu"'),'Sync must verify the staged file owner.');
olssAssert(str_contains($syncSource,"stat -c '%a'") && str_contains($syncSource,'== "600"'),'Sync must require mode 0600.');
olssAssert(str_contains($syncSource,'install -o root -g www-data -m 0640'),'Final login env must remain root:www-data 0640.');
olssAssert(!str_contains($syncSource,'cat "$INBOX_FILE"'),'Sync must never print login env contents.');

olssAssert(str_contains($requestSource,'openssl req -x509 -newkey rsa:4096'),'Requester must generate an ephemeral 4096-bit recipient keypair locally.');
olssAssert(str_contains($requestSource,'gh workflow run "$WORKFLOW"'),'Requester must dispatch the protected envelope workflow.');
olssAssert(str_contains($requestSource,'gh run download "$run_id"'),'Requester must download only the selected workflow run artifact.');
olssAssert(str_contains($requestSource,'openssl cms -decrypt'),'Requester must decrypt the envelope only on the host.');
olssAssert(str_contains($requestSource,'install -m 0600'),'Requester must stage plaintext credentials as mode 0600.');
olssAssert(str_contains($requestSource,'display_title == $title'),'Requester must correlate the exact workflow dispatch.');
olssAssert(!str_contains($requestSource,'cat "$tmp/olist-erp-browser-login.env"'),'Requester must never print decrypted credentials.');

olssAssert(str_contains($workflowSource,'workflow_dispatch:'),'Envelope workflow must require explicit dispatch.');
olssAssert(str_contains($workflowSource,'request_id:') && str_contains($workflowSource,'run-name: Olist Login Envelope ${{ inputs.request_id }}'),'Envelope workflow must expose a non-secret correlation identifier.');
olssAssert(str_contains($workflowSource,'recipient_cert_b64'),'Envelope workflow must encrypt to an operator-supplied public certificate.');
olssAssert(str_contains($workflowSource,'openssl cms -encrypt'),'Envelope workflow must use recipient encryption.');
olssAssert(str_contains($workflowSource,'path: olist-login.cms'),'Only the encrypted CMS payload may be uploaded.');
olssAssert(!str_contains($workflowSource,'path: olist-login.env' . PHP_EOL),'Plaintext login env must never be uploaded.');
olssAssert(!str_contains($workflowSource,'OLIST_ENV_SYNC_KEY'),'Unused symmetric sync secret must not remain in the envelope path.');

$syncPos=strpos($autoSource,'sync_olist_login_inbox');
$currentPos=strpos($autoSource,"auto_deploy_skipped=already_current");
olssAssert($syncPos!==false && $currentPos!==false && $syncPos<$currentPos,'Auto-deploy must consume a staged login env even when code is already current.');
olssAssert(str_contains($autoSource,'systemctl restart amazon-returns-olist-erp-browser.service'),'Successful credential sync must restart only the Olist browser service.');
olssAssert(str_contains($provisionSource,'LOGIN_SYNC_SCRIPT'),'Provisioning must also consume a staged login env safely.');

$tmp=tempnam(sys_get_temp_dir(),'olist-login-validator-');
olssAssert(is_string($tmp) && $tmp!=='','Unable to create validator fixture.');
try{
    file_put_contents($tmp,"OLIST_ERP_LOGIN_EMAIL=user@example.test\nOLIST_ERP_LOGIN_PASSWORD=placeholder-secret\n");
    chmod($tmp,0600);
    exec('python3 '.escapeshellarg($validator).' '.escapeshellarg($tmp).' 2>/dev/null',$out,$rc);
    olssAssert($rc===0,'Validator must accept exactly the two required non-empty keys.');

    file_put_contents($tmp,"OLIST_ERP_LOGIN_EMAIL=user@example.test\nOLIST_ERP_LOGIN_PASSWORD=placeholder-secret\nUNEXPECTED=value\n");
    exec('python3 '.escapeshellarg($validator).' '.escapeshellarg($tmp).' 2>/dev/null',$out,$rc);
    olssAssert($rc!==0,'Validator must reject unexpected keys.');
}finally{
    @unlink($tmp);
}

echo "olist-login-secret-sync-test: OK\n";
