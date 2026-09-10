<?php
declare(strict_types=1);

final class SvAmazonSellerCentralWake
{
    private const VERSION=1;

    public static function request(
        string $source='known-action',
        int $attempt=1,
        ?DateTimeImmutable $now=null
    ): bool {
        $configured=getenv('AMAZON_RETURNS_SELLER_CENTRAL_WAKE_FILE');
        $path=is_string($configured)?trim($configured):'';
        if($path==='')return false;
        self::assertPath($path);
        if(!in_array($source,['known-action','retry'],true)){
            throw new InvalidArgumentException('Seller Central wake source is invalid.');
        }
        if($attempt<1 || $attempt>3){
            throw new InvalidArgumentException('Seller Central wake attempt is invalid.');
        }
        $now=($now ?? new DateTimeImmutable('now',new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'));
        $marker=[
            'version'=>self::VERSION,
            'requested_at'=>$now->format(DATE_ATOM),
            'source'=>$source,
            'attempt'=>$attempt,
        ];
        $json=json_encode($marker,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
        $dir=dirname($path);
        $tmp=$dir.'/.wake.'.bin2hex(random_bytes(8)).'.tmp';
        $mask=umask(0077);
        try{
            $handle=@fopen($tmp,'xb');
        }finally{
            umask($mask);
        }
        if(!is_resource($handle)){
            throw new RuntimeException('Unable to create Seller Central wake marker.');
        }
        $published=false;
        try{
            if(!@chmod($tmp,0660)){
                throw new RuntimeException('Unable to restrict Seller Central wake marker permissions.');
            }
            $written=fwrite($handle,$json);
            if($written===false || $written!==strlen($json)){
                throw new RuntimeException('Unable to write Seller Central wake marker.');
            }
            if(!fflush($handle)){
                throw new RuntimeException('Unable to flush Seller Central wake marker.');
            }
            if(function_exists('fsync'))@fsync($handle);
            fclose($handle);
            $handle=null;
            if(!@rename($tmp,$path)){
                throw new RuntimeException('Unable to publish Seller Central wake marker.');
            }
            $published=true;
            return true;
        } finally {
            if(is_resource($handle))fclose($handle);
            if(!$published && is_file($tmp))@unlink($tmp);
        }
    }

    private static function assertPath(string $path): void
    {
        // Match the deploy root used by provisioning; never create host directories.
        $configuredRoot=getenv('AMAZON_RETURNS_DEPLOY_ROOT');
        $root=is_string($configuredRoot) && trim($configuredRoot)!==''
            ? rtrim(trim($configuredRoot),'/') : '/home/ubuntu/amazon-returns-deploy';
        $dir=$root.'/shared/seller-central-wake';
        if($root==='' || $root[0]!=='/' || $path!==$dir.'/wake.json'
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)|//|[\x00-\x1f]~',$path)){
            throw new InvalidArgumentException('Seller Central wake marker path is invalid.');
        }
        clearstatcache(true,$dir);
        if(!is_dir($dir) || !is_writable($dir)){
            throw new RuntimeException('Seller Central wake directory is unavailable.');
        }
        if(realpath($dir)!==$dir || is_link($path)){
            throw new InvalidArgumentException('Seller Central wake marker directory is invalid.');
        }
        if(file_exists($path) && !is_file($path)){
            throw new InvalidArgumentException('Seller Central wake marker is not a regular file.');
        }
    }
}
