<?php
declare(strict_types=1);
// Cold loads (clean browser context) requested /favicon.ico, which returns 404 and logs a console error.
// Every page with a <head> must declare an icon explicitly so no implicit favicon request is made.
$root=dirname(__DIR__);
$pages=['login.php','admin/amazon-returns/index.php','admin/amazon-returns/intake.php'];
foreach($pages as $page){
    $source=(string)file_get_contents($root.'/'.$page);
    if(preg_match('/<link[^>]+rel=["\']icon["\'][^>]*>/i',$source)!==1){
        fwrite(STDERR,"FAIL: $page must declare <link rel=\"icon\"> to avoid an implicit /favicon.ico 404.\n");
        exit(1);
    }
}
echo "page-favicon-test OK\n";
