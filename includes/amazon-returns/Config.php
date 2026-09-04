<?php

declare(strict_types=1);

final class SvAmazonReturnsConfig
{
    /** @param array<string,string> $override */
    public function __construct(private array $override = []) {}

    public function enabled(): bool { return $this->bool('AMAZON_RETURNS_ENABLED', false); }

    public function mode(): string
    {
        $mode = strtolower(trim($this->get('AMAZON_RETURNS_MODE', 'dry-run')));
        return in_array($mode, ['development','dry-run','shadow','production'], true) ? $mode : 'dry-run';
    }

    public function flag(string $name): bool
    {
        $map = [
            'gmail_ingest' => 'AMAZON_RETURNS_GMAIL_INGEST',
            'safe_t_write' => 'AMAZON_RETURNS_SAFE_T_WRITE',
            'appeal_write' => 'AMAZON_RETURNS_APPEAL_WRITE',
            'email_review_write' => 'AMAZON_RETURNS_EMAIL_REVIEW_WRITE',
            'support_write' => 'AMAZON_RETURNS_SUPPORT_WRITE',
            'policy_monitor' => 'AMAZON_RETURNS_POLICY_MONITOR',
        ];
        return isset($map[$name]) ? $this->bool($map[$name], false) : false;
    }

    public function externalWriteAllowed(string $action): bool
    {
        if (!$this->enabled() || $this->mode() !== 'production') return false;
        return match (strtoupper(trim($action))) {
            'SAFE_T_SUBMIT' => $this->flag('safe_t_write'),
            'SAFE_T_APPEAL' => $this->flag('appeal_write'),
            'SAFE_T_EMAIL_REVIEW' => $this->flag('email_review_write'),
            'SELLER_SUPPORT_OPEN', 'SELLER_SUPPORT_UPDATE' => $this->flag('support_write'),
            default => false,
        };
    }

    public function sellerCentralBridgeMode(): string
    {
        if ($this->get('SELLER_CENTRAL_BROWSER_BRIDGE_URL') !== '') return 'direct';
        if ($this->get('SELLER_CENTRAL_BRIDGE_TOKEN') !== '') return 'polling';
        return 'unavailable';
    }

    /** @return array<string,array{ready:bool,missing:list<string>}> */
    public function readiness(): array
    {
        $bridgeReady = $this->sellerCentralBridgeMode() !== 'unavailable';
        return [
            'sp_api' => $this->requirements(['AMAZON_LWA_CLIENT_ID','AMAZON_LWA_CLIENT_SECRET','AMAZON_LWA_REFRESH_TOKEN']),
            'gmail' => $this->requirementsAny([
                ['GMAIL_OAUTH_CLIENT_ID','GOOGLE_OAUTH_CLIENT_ID'],
                ['GMAIL_OAUTH_CLIENT_SECRET','GOOGLE_OAUTH_CLIENT_SECRET'],
                ['GMAIL_OAUTH_REFRESH_TOKEN','GOOGLE_OAUTH_REFRESH_TOKEN'],
            ]),
            'seller_central_bridge' => $bridgeReady
                ? ['ready'=>true,'missing'=>[]]
                : ['ready'=>false,'missing'=>['SELLER_CENTRAL_BROWSER_BRIDGE_URL|SELLER_CENTRAL_BRIDGE_TOKEN']],
        ];
    }

    /** @return array<string,bool> */
    public function writeFlags(): array
    {
        return [
            'SAFE_T_SUBMIT' => $this->externalWriteAllowed('SAFE_T_SUBMIT'),
            'SAFE_T_APPEAL' => $this->externalWriteAllowed('SAFE_T_APPEAL'),
            'SAFE_T_EMAIL_REVIEW' => $this->externalWriteAllowed('SAFE_T_EMAIL_REVIEW'),
            'SELLER_SUPPORT_OPEN' => $this->externalWriteAllowed('SELLER_SUPPORT_OPEN'),
            'SELLER_SUPPORT_UPDATE' => $this->externalWriteAllowed('SELLER_SUPPORT_UPDATE'),
        ];
    }

    public function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->override)) return trim((string)$this->override[$key]);
        $value = getenv($key);
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    public function first(string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== '') return $value;
        }
        return '';
    }

    private function bool(string $key, bool $default): bool
    {
        $raw = strtolower($this->get($key, $default ? '1' : '0'));
        return in_array($raw, ['1','true','yes','on'], true);
    }

    /** @param list<string> $keys @return array{ready:bool,missing:list<string>} */
    private function requirements(array $keys): array
    {
        $missing = [];
        foreach ($keys as $key) {
            if ($this->get($key) === '') $missing[] = $key;
        }
        return ['ready'=>$missing === [], 'missing'=>$missing];
    }

    /** @param list<list<string>> $groups @return array{ready:bool,missing:list<string>} */
    private function requirementsAny(array $groups): array
    {
        $missing = [];
        foreach ($groups as $keys) {
            if ($this->first(...$keys) === '') $missing[] = implode('|', $keys);
        }
        return ['ready'=>$missing === [], 'missing'=>$missing];
    }
}
