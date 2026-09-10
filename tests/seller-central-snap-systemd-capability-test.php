<?php
$unit=file_get_contents(__DIR__.'/../deploy/systemd/amazon-returns-seller-central-browser.service');
if($unit===false){fwrite(STDERR,"unit missing\n");exit(1);}
if(str_contains($unit,"NoNewPrivileges=true")){fwrite(STDERR,"FAIL: snap Chromium cannot start under NoNewPrivileges=true because snap-confine requires capabilities\n");exit(1);}
echo "PASS: Seller Central browser unit permits snap-confine capability setup\n";
