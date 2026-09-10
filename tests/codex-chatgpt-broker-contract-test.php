<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$wrapper=$root.'/scripts/amazon-returns/codex-review-wrapper.sh';
$broker=$root.'/scripts/amazon-returns/codex-review-broker.php';
$schema=$root.'/scripts/amazon-returns/codex-review-schema.json';
$unit=$root.'/deploy/systemd/amazon-returns-codex-review.service';
foreach([$wrapper,$broker,$schema,$unit] as $file)if(!is_file($file))throw new RuntimeException('Missing Codex review component: '.basename($file));
$w=(string)file_get_contents($wrapper);$b=(string)file_get_contents($broker);$u=(string)file_get_contents($unit);$provision=(string)file_get_contents($root.'/scripts/provision-production.sh');
foreach(['--ephemeral','--ignore-user-config','--ignore-rules','--sandbox read-only','--skip-git-repo-check','--output-schema','/home/ubuntu/.codex/auth.json','--disable shell_tool','--disable browser_use','--disable computer_use','--disable apps','--disable plugins'] as $needle)if(!str_contains($w,$needle))throw new RuntimeException('Codex wrapper missing isolation control '.$needle);
if(str_contains($w,'sudo '))throw new RuntimeException('Codex advisory wrapper must not use sudo.');
foreach(['stream_socket_server','131072','codex-review-wrapper.sh'] as $needle)if(!str_contains($b,$needle))throw new RuntimeException('Codex broker missing '.$needle);
foreach(['User=ubuntu','Group=www-data','NoNewPrivileges=true','ProtectSystem=strict','RuntimeDirectory=amazon-returns-safet'] as $needle)if(!str_contains($u,$needle))throw new RuntimeException('Codex systemd isolation missing '.$needle);
if(str_contains($provision,'sudoers'))throw new RuntimeException('Production provisioning must not install Codex sudoers rules.');
if(!str_contains($provision,'amazon-returns-codex-review.service'))throw new RuntimeException('Production must install the Codex review broker service.');
echo "codex-chatgpt-broker-contract-test: OK\n";