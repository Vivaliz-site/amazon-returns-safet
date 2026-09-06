<?php

declare(strict_types=1);

final class SvAmazonReturnsConfig
{
    /** @param array<string,string> $override */
    public function __construct(private array $override = []) {}

    public function enabled(): bool { return $this->bool('AMAZON_RETURNS_ENABLED', false); }
    public function openAiKey(): string { return $this->get('OPENAI_API_KEY'); }
    public function reviewAiModel(): string { return $this->get('AMAZON_RETURNS_REVIEW_AI_MODEL','gpt-5.6-terra'); }
    public function reviewAiReady(): bool { return $this->openAiKey()!=='' && $this->reviewAiModel()!==''; }
    public function learnedRuleExecutionEnabled(): bool { return $this->bool('AMAZON_RETURNS_LEARNED_RULE_EXECUTION', false); }

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
            'email_reply_write' => 'AMAZON_RETURNS_EMAIL_REPLY_WRITE',
            'support_write' => 'AMAZON_RETURNS_SUPPORT_WRITE',
            'policy_monitor' => 'AMAZON_RETURNS_POLICY_MONITOR',
        ];
        return isset($map[$name]) ? $this->bool($map[$name], false) : false;
    }

    public function externalWriteAllowed(string $action): bool
    {
        if (!$this->enabled() || $this->mode() !== 'production') return false;
        if ($this->bool('AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH', false)) return false;
        $profile=$this->writeProfile();
        if($profile===null)return false;
        $action=strtoupper(trim($action));
        return isset($profile[$action]) && $profile[$action]===true;
    }

    public function writeProfileVersion(): ?string
    {
        $profile=$this->writeProfile();
        return is_array($profile)?(string)$profile['version']:null;
    }

    /** @return array<string,bool|string>|null */
    private function writeProfile(): ?array
    {
        $default=dirname(__DIR__,2).'/deploy/write-profile.json';
        $path=$this->get('AMAZON_RETURNS_WRITE_PROFILE_FILE',$default);
        if($path==='' || !is_file($path) || !is_readable($path))return null;
        $raw=@file_get_contents($path);
        if(!is_string($raw) || trim($raw)==='')return null;
        try{$profile=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
        if(!is_array($profile))return null;
        $actions=['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'];
        $allowed=array_merge(['version'],$actions);
        $keys=array_keys($profile);sort($keys);$expected=$allowed;sort($expected);
        if($keys!==$expected)return null;
        $version=$profile['version']??null;
        if(!is_string($version) || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D',$version)!==1)return null;
        foreach($actions as $action)if(!is_bool($profile[$action]??null))return null;
        return $profile;
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
            'SAFE_T_EMAIL_REPLY' => $this->externalWriteAllowed('SAFE_T_EMAIL_REPLY'),
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
