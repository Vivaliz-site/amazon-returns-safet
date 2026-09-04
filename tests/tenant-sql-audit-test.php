<?php
declare(strict_types=1);

function tsaRemove(string $path):void
{
    if(!file_exists($path))return;
    if(is_file($path) || is_link($path)){unlink($path);return;}
    foreach(scandir($path) ?: [] as $entry){
        if($entry==='.' || $entry==='..')continue;
        tsaRemove($path.'/'.$entry);
    }
    rmdir($path);
}

$script=__DIR__.'/../scripts/audit-tenant-sql.php';
if(!is_file($script))throw new RuntimeException('tenant SQL audit missing');
exec('php '.escapeshellarg($script).' 2>&1',$output,$status);
if($status!==0){
    throw new RuntimeException("tenant SQL audit failed:\n".implode("\n",$output));
}
$source=(string)file_get_contents($script);
foreach(['token_get_all','T_CONSTANT_ENCAPSED_STRING','path:line:table'] as $needle){
    if(!str_contains($source,$needle))throw new RuntimeException('audit contract missing '.$needle);
}
foreach(['getenv','$_ENV','$_SERVER'] as $forbidden){
    if(str_contains($source,$forbidden))throw new RuntimeException('audit must not read environment data: '.$forbidden);
}

$root=sys_get_temp_dir().'/amazon-tenant-audit-'.bin2hex(random_bytes(6));
mkdir($root.'/workers',0700,true);
mkdir($root.'/includes/amazon-returns',0700,true);
try{
    file_put_contents(
        $root.'/workers/CommentOnly.php',
        "<?php\n// SELECT * FROM amazon_return_cases should not count.\n"
    );
    file_put_contents(
        $root.'/includes/amazon-returns/CaseRepository.php',
        "<?php\n\$sql='SELECT * FROM amazon_return_cases';\n"
    );
    file_put_contents(
        $root.'/workers/Bad.php',
        "<?php\n\$secret='top-secret-value';\n\$sql='SELECT * FROM amazon_return_cases';\n"
    );
    $command='php '.escapeshellarg($script).' --root='.escapeshellarg($root).' 2>&1';
    exec($command,$badOutput,$badStatus);
    if($badStatus!==1)throw new RuntimeException('audit did not reject unapproved SQL');
    $badText=implode("\n",$badOutput);
    if(!str_contains($badText,'workers/Bad.php:3:amazon_return_cases')){
        throw new RuntimeException('audit output lacks stable path:line:table finding');
    }
    if(str_contains($badText,'CommentOnly') || str_contains($badText,'top-secret-value')){
        throw new RuntimeException('audit leaked content or flagged a comment');
    }
    unlink($root.'/workers/Bad.php');
    exec($command,$cleanOutput,$cleanStatus);
    if($cleanStatus!==0){
        throw new RuntimeException("clean fixture failed:\n".implode("\n",$cleanOutput));
    }
}finally{
    tsaRemove($root);
}

echo "tenant-sql-audit-test: OK\n";
