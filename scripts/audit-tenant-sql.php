<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){
    fwrite(STDERR,"CLI only\n");
    exit(2);
}

// Stable output contract: path:line:table
$root=dirname(__DIR__);
foreach(array_slice($argv,1) as $argument){
    if(str_starts_with($argument,'--root=')){
        $candidate=realpath(substr($argument,7));
        if($candidate===false || !is_dir($candidate)){
            fwrite(STDERR,"Invalid audit root\n");
            exit(2);
        }
        $root=$candidate;
    }
}

$scanDirectories=['includes','api','admin','workers','scripts'];
$tenantTables=[
    'amazon_return_tenants',
    'amazon_return_tenant_users',
    'amazon_return_connections',
    'amazon_return_feature_flags',
    'amazon_return_browser_agents',
    'amazon_return_cases',
    'amazon_return_events',
    'amazon_return_policies',
    'amazon_return_evidence',
    'amazon_return_outbox',
    'amazon_return_dead_letters',
    'amazon_return_source_cursors',
    'amazon_return_overrides',
];
$allowlist=array_fill_keys([
    'includes/amazon-returns/Schema.php',
    'includes/amazon-returns/TenantMigration.php',
    'includes/amazon-returns/TenantRegistry.php',
    'includes/amazon-returns/CaseRepository.php',
    'includes/amazon-returns/EvidenceStore.php',
    'includes/amazon-returns/PolicyRepository.php',
    'includes/amazon-returns/ShadowAuditRepository.php',
    'includes/amazon-returns/SourceCursorStore.php',
    'includes/amazon-returns/TenantEventStore.php',
    'includes/amazon-returns/TenantOutbox.php',
],true);

$files=[];
foreach($scanDirectories as $directory){
    $path=$root.'/'.$directory;
    if(!is_dir($path))continue;
    $iterator=new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)
    );
    foreach($iterator as $file){
        if(!$file instanceof SplFileInfo || !$file->isFile())continue;
        if(strtolower($file->getExtension())!=='php')continue;
        $files[]=$file->getPathname();
    }
}
sort($files,SORT_STRING);
$violations=[];
foreach($files as $path){
    $relative=str_replace('\\','/',substr($path,strlen($root)+1));
    if(isset($allowlist[$relative]))continue;
    $source=file_get_contents($path);
    if(!is_string($source)){
        fwrite(STDERR,"Could not read {$relative}\n");
        exit(2);
    }
    foreach(token_get_all($source) as $token){
        if(!is_array($token) || $token[0]!==T_CONSTANT_ENCAPSED_STRING)continue;
        $literal=(string)$token[1];
        if(preg_match('/\b(?:SELECT|FROM|JOIN|INSERT|UPDATE|DELETE)\b/i',$literal)!==1){
            continue;
        }
        foreach($tenantTables as $table){
            if(stripos($literal,$table)===false)continue;
            $violations[]=$relative.':'.(int)$token[2].':'.$table;
        }
    }
}
$violations=array_values(array_unique($violations));
sort($violations,SORT_STRING);
foreach($violations as $violation)fwrite(STDERR,$violation."\n");
if($violations!==[])exit(1);
echo "tenant_sql_audit=ok files=".count($files)."\n";
