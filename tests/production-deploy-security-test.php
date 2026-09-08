<?php
declare(strict_types=1);

function pdsAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$scriptPath = __DIR__ . '/../scripts/provision-production.sh';
$vhostPath = __DIR__ . '/../deploy/apache/returns.shopvivaliz.com.br.conf';
$bootstrapVhostPath = __DIR__ . '/../deploy/apache/returns-http.conf';

pdsAssert(is_file($scriptPath), 'Production provision script must exist.');
pdsAssert(is_file($vhostPath), 'Production TLS vhost must exist.');
pdsAssert(is_file($bootstrapVhostPath), 'TLS bootstrap HTTP vhost must exist.');

$script = (string) file_get_contents($scriptPath);
$vhost = (string) file_get_contents($vhostPath);
$bootstrapVhost = (string) file_get_contents($bootstrapVhostPath);

pdsAssert(
    str_contains($bootstrapVhost, 'Redirect permanent / https://returns.shopvivaliz.com.br/'),
    'TLS bootstrap HTTP vhost must redirect every request to HTTPS.'
);
pdsAssert(
    !str_contains($bootstrapVhost, 'DocumentRoot'),
    'TLS bootstrap HTTP vhost must never expose the application document root.'
);
pdsAssert(
    !str_contains($bootstrapVhost, '<Directory'),
    'TLS bootstrap HTTP vhost must never grant filesystem access to the application.'
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
    'TLS credentials must exist before the production HTTPS vhost is activated.'
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
