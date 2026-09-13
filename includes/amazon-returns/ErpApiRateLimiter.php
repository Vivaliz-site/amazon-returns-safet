<?php
declare(strict_types=1);

require_once __DIR__.'/Config.php';

final class SvAmazonErpRateLimitException extends RuntimeException {}

final class SvAmazonErpApiRateLimiter
{
    /** @var callable():float */
    private $clock;
    /** @var callable(int):void */
    private $sleep;
    private ?float $lastRequestAt=null;

    public function __construct(private int $minIntervalMs=1000,?callable $clock=null,?callable $sleep=null)
    {
        $this->minIntervalMs=max(250,min(5000,$this->minIntervalMs));
        $this->clock=$clock ?? static fn():float=>microtime(true);
        $this->sleep=$sleep ?? static function(int $microseconds):void { if($microseconds>0)usleep($microseconds); };
    }

    public static function fromConfig(SvAmazonReturnsConfig $config,?callable $clock=null,?callable $sleep=null): self
    {
        return new self((int)$config->get('AMAZON_RETURNS_ERP_READ_INTERVAL_MS','1000'),$clock,$sleep);
    }

    public function minIntervalMs(): int { return $this->minIntervalMs; }

    public function beforeRequest(): void
    {
        $now=($this->clock)();
        if($this->lastRequestAt!==null){
            $elapsedMs=max(0.0,($now-$this->lastRequestAt)*1000);
            $waitMs=$this->minIntervalMs-$elapsedMs;
            if($waitMs>0){
                ($this->sleep)((int)ceil($waitMs*1000));
                $now=($this->clock)();
            }
        }
        $this->lastRequestAt=$now;
    }
}
