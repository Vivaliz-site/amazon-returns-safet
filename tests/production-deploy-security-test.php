<?php
declare(strict_types=1);

function pdsAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$scriptPath = __DIR__ . '/../scripts/provision-production.sh';
$vhostPath = __DIR__ . '/../deploy/apache/returns.shopvivaliz.com.br.conf';

pdsAssert(is_file($scriptPath), 'Production provision script must exist.');
pdsAssert(is_file($vhostPath), 'Production TLS vhost must exist.');

$script = (string) file_get_contents($scriptPath);
$vhost = (string) file_get_contents($vhostPath);

pdsAssert(
    !str_contains($script, 'deploy/apache/returns-http.conf'),
    'Provisioning must never publish the application through the HTTP-only vhost.'
);

$tlsBootstrap = strpos(
    $script,
    'if [[ ! -s /etc/letsencrypt/live/returns.shopvivaliz.com.br/fullchain.pem ]]'
);
$secureVhostInstall = strpos(
    $script,
    'deploy/apache/returns.shopvivaliz.com.br.conf'
);
pdsAssert($tlsBootstrap !== false, 'Provisioning must verify/bootstrap TLS credentials.');
pdsAssert($secureVhostInstall !== false, 'Provisioning must install the secure vhost.');
pdsAssert(
    $tlsBootstrap < $secureVhostInstall,
    'TLS credentials must exist before the production vhost is activated.'
);

$beforeSecureVhost = substr($script, 0, $secureVhostInstall);
pdsAssert(
    !str_contains($beforeSecureVhost, 'systemctl reload apache2'),
    'Apache must not be reloaded with an application-serving vhost before TLS/mTLS is ready.'
);

pdsAssert(
    str_contains($vhost, 'Redirect permanent / https://returns.shopvivaliz.com.br/'),
    'Production HTTP must only redirect to HTTPS.'
);
pdsAssert(
    str_contains($vhost, 'SSLVerifyClient require'),
    'Production origin must require authenticated Cloudflare origin pulls.'
);

echo "production-deploy-security-test: OK\n";
