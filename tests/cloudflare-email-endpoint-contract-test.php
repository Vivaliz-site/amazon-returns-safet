<?php
declare(strict_types=1);

$path=__DIR__ . '/../api/amazon-returns/email-ingress.php';
if(!is_file($path)){
    fwrite(STDERR,"Cloudflare email ingress endpoint is missing.\n");
    exit(1);
}
$code=(string)file_get_contents($path);
$needles=[
    "HTTP_CF_ACCESS_JWT_ASSERTION",
    "CloudflareEmailIngress.php",
    "GmailEventSink.php",
    "SvAmazonCloudflareEmailIngress::parse",
    "SvAmazonGmailEventSink::persist",
    "REQUEST_METHOD",
    "PAYLOAD_TOO_LARGE",
    "UNAUTHORIZED",
];
foreach($needles as $needle){
    if(!str_contains($code,$needle)){
        fwrite(STDERR,"Endpoint contract missing: {$needle}\n");
        exit(1);
    }
}
echo "cloudflare-email-endpoint-contract-test: OK\n";
