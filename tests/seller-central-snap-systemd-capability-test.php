<?php
$unit=file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-seller-central-browser.service');
if($unit===false){fwrite(STDERR,"unit missing\n");exit(1);}
if(str_contains($unit,"NoNewPrivileges=true")){fwrite(STDERR,"FAIL: snap Chromium cannot start under NoNewPrivileges=true because snap-confine requires capabilities\n");exit(1);}
$auth=file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-seller-central-auth-check.service');
if($auth===false){fwrite(STDERR,"auth unit missing\n");exit(1);}
if(str_contains($auth,"NoNewPrivileges=true")){fwrite(STDERR,"FAIL: auth smoke also launches snap Chromium and cannot use NoNewPrivileges=true\n");exit(1);}
echo "PASS: Seller Central browser units permit snap-confine capability setup\n";
