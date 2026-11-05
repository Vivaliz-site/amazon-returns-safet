<?php

declare(strict_types=1);

final class SvAmazonReturnsConfig
{
    // Explicit owner-approved, mutually exclusive one-action canaries.
    // These are configuration guards only: an external certified operator
    // must still enforce owner PR, one-use, tenant, lease and Amazon readback.
    private const SCOPED_SAFE_T_GRANTS = [
        'safet-d45-pr3556-scoped-v1' => [
            'expires_at' => '2026-11-09T23:59:00Z',
            'case_ids' => [1263,1314,8803,13210,51429,101669,101670,101671],
        ],
        'safet-d45-pr3673-scoped-v1' => [
            'expires_at' => '2026-11-30T23:59:00Z',
            'case_ids' => [42471,51429,101669,101670,101671],
        ],
    ];
    /** @var array<string,string>|null */
    private ?array $runtimeEnv = null;

    /** @param array<string,string> $override */
    public function __construct(private array $override = []) {}

    public function enabled(): bool { return $this->bool('AMAZON_RETURNS_ENABLED', false); }
    public function openAiKey(): string { return $this->aiSecret('OPENAI_API_KEY'); }
    public function anthropicKey(): string { return $this->aiSecret('ANTHROPIC_API_KEY','CLAUDE_API_KEY'); }
    public function geminiKey(): string { return $this->aiSecret('GEMINI_API_KEY','GOOGLE_API_KEY','GOOGLE_IMAGEN_API_KEY'); }
    public function reviewAiModel(): string { return $this->get('AMAZON_RETURNS_REVIEW_AI_MODEL','gpt-5.6-terra'); }
    public function reviewAiAnthropicModel(): string { return $this->get('AMAZON_RETURNS_REVIEW_AI_ANTHROPIC_MODEL','claude-sonnet-4-6'); }
    public function reviewAiGeminiModel(): string { return $this->get('AMAZON_RETURNS_REVIEW_AI_GEMINI_MODEL','gemini-3.5-flash-lite'); }
    public function reviewAiGeminiFallbackModel(): string { return $this->get('AMAZON_RETURNS_REVIEW_AI_GEMINI_FALLBACK_MODEL','gemini-3.1-flash-lite'); }
    public function reviewAiReady(): bool { return $this->openAiKey()!=='' || $this->anthropicKey()!=='' || $this->geminiKey()!==''; }
    public function learnedRuleExecutionEnabled(): bool { return $this->bool('AMAZON_RETURNS_LEARNED_RULE_EXECUTION', false); }
    public function erpSalesReturnCreateEnabled(): bool
    {
        return $this->bool('AMAZON_RETURNS_ERP_SALES_RETURN_CREATE_ENABLED', false)
            && $this->externalWriteAllowed('ERP_SALES_RETURN_CREATE');
    }

    public function mode(): string
    {
        $mode = strtolower(trim($this->get('AMAZON_RETURNS_MODE', 'dry-run')));
        return in_array($mode, ['development','dry-run','shadow','production'], true) ? $mode : 'dry-run';
    }

    public function fulfillmentProgramOverride(): string
    {
        $program=strtoupper(trim($this->get('AMAZON_RETURNS_FULFILLMENT_PROGRAM_OVERRIDE','')));
        if($program==='')return '';
        if($program!=='FBA_ONSITE'){
            throw new UnexpectedValueException('Unsupported Amazon Returns fulfillment program override.');
        }
        return $program;
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
        // A missing, blank or malformed kill-switch value must NEVER unlock
        // financial/provider writes. Only explicit recognized OFF values can
        // proceed to the narrower per-action profile and case guards.
        $killSwitch=strtolower($this->get('AMAZON_RETURNS_EXTERNAL_WRITES_KILL_SWITCH', '1'));
        if (!in_array($killSwitch, ['0','false','no','off'], true)) return false;
        $profile=$this->writeProfile();
        if($profile===null)return false;
        $action=strtoupper(trim($action));
        // Profile alone is not an owner grant or operational permission.
        // Its immutable deadline and EXACT case set are additional backstops.
        if (isset(self::SCOPED_SAFE_T_GRANTS[$profile['version']])) {
            if (!self::scopedSafeTGrantActiveAt($profile['version'], new \DateTimeImmutable('now',new \DateTimeZone('UTC')))) return false;
            if ($action==='SAFE_T_SUBMIT' && $this->scopedWriteCaseIds()===[]) return false;
        }
        return isset($profile[$action]) && $profile[$action]===true;
    }

    /**
     * Return only Seller Central kinds with a literal TRUE write flag.
     * Filter before outbox claimBatch/SELECT FOR UPDATE, not after a lease
     * has already changed a disabled job's status or attempt count.
     *
     * @param array<string,mixed> $flags
     * @return list<string>
     */
    public static function sellerCentralWriteKinds(array $flags): array
    {
        $trusted=['SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'];
        return array_values(array_filter(
            $trusted,
            static fn(string $kind): bool => ($flags[$kind] ?? false) === true
        ));
    }

    public static function scopedD45GrantActiveAt(\DateTimeImmutable $instant): bool
    {
        return self::scopedSafeTGrantActiveAt('safet-d45-pr3556-scoped-v1', $instant);
    }

    public static function scopedSafeTGrantActiveAt(string $version, \DateTimeImmutable $instant): bool
    {
        $expires=self::SCOPED_SAFE_T_GRANTS[$version]['expires_at']??null;
        if (!is_string($expires)) return false;
        // Exclusive deadline: runtime cannot set or extend an expiry.
        return $instant->getTimestamp() < (new \DateTimeImmutable($expires))->getTimestamp();
    }

    public function writeProfileVersion(): ?string
    {
        $profile=$this->writeProfile();
        return is_array($profile)?(string)$profile['version']:null;
    }

    public function writeConfigurationRevision(): string
    {
        $payload=['profile_version'=>$this->writeProfileVersion(),'flags'=>$this->writeFlags()];
        return hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    /** @return list<int>|null Null retains the original unscoped profile behavior. */
    public function scopedWriteCaseIds(): ?array
    {
        $version=$this->writeProfileVersion();
        $ids=self::SCOPED_SAFE_T_GRANTS[$version]['case_ids']??null;
        if (!is_array($ids)) return null;
        // Any partial, missing, duplicate, cross-grant or expanded canary
        // must fail before the outbox acquires a lease or alters a job.
        foreach ($ids as $id) {
            if (!$this->writeCaseAllowed($id)) return [];
        }
        return $ids;
    }

    public function writeCaseAllowed(int $caseId): bool
    {
        if ($caseId < 1) return false;
        $raw=$this->get('AMAZON_RETURNS_WRITE_CANARY_CASE_IDS');
        // Each approved profile has its own exact, non-interchangeable set.
        $required=self::SCOPED_SAFE_T_GRANTS[$this->writeProfileVersion()]['case_ids']??null;
        $scoped=is_array($required);
        if ($raw==='') return !$scoped;
        $allowed=[];
        foreach (explode(',',$raw) as $part) {
            $part=trim($part);
            if ($part==='' || preg_match('/^[1-9][0-9]*$/D',$part)!==1) return false;
            $id=(int)$part;
            if ($scoped && isset($allowed[$id])) return false;
            $allowed[$id]=true;
        }
        if ($scoped) {
            if (count($allowed)!==count($required)) return false;
            foreach ($required as $id) {
                if (!isset($allowed[$id])) return false;
            }
        }
        return isset($allowed[$caseId]);
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
        $requiredActions=['SAFE_T_SUBMIT','SAFE_T_APPEAL','SAFE_T_EMAIL_REVIEW','SAFE_T_EMAIL_REPLY','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'];
        $optionalActions=['ERP_SALES_RETURN_CREATE'];
        $allowed=array_merge(['version'],$requiredActions,$optionalActions);
        $unknown=array_diff(array_keys($profile),$allowed);
        if($unknown!==[])return null;
        $version=$profile['version']??null;
        if(!is_string($version) || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/D',$version)!==1)return null;
        foreach($requiredActions as $action){
            if(!array_key_exists($action,$profile) || !is_bool($profile[$action]))return null;
        }
        foreach($optionalActions as $action){
            if(array_key_exists($action,$profile) && !is_bool($profile[$action]))return null;
            if(!array_key_exists($action,$profile))$profile[$action]=false;
        }
        // Do not allow unknown forged versions that look like a scoped grant:
        // they would otherwise inherit the unscoped legacy case behavior.
        if(str_starts_with($version,'safet-d45-pr') && !isset(self::SCOPED_SAFE_T_GRANTS[$version]))return null;
        // Neither owner-granted version can borrow any other write action.
        if(isset(self::SCOPED_SAFE_T_GRANTS[$version])){
            foreach(array_merge($requiredActions,$optionalActions) as $action){
                if($profile[$action]!==($action==='SAFE_T_SUBMIT'))return null;
            }
        }
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
            'ERP_SALES_RETURN_CREATE' => $this->erpSalesReturnCreateEnabled(),
        ];
    }

    public function get(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->override)) return trim((string)$this->override[$key]);
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') return trim($value);
        $runtime=$this->runtimeEnvironment();
        if(array_key_exists($key,$runtime)){
            $value=trim((string)$runtime[$key]);
            if($value!=='')return $value;
        }
        return $default;
    }

    /** @return array<string,string> */
    private function runtimeEnvironment(): array
    {
        if($this->runtimeEnv!==null)return $this->runtimeEnv;
        $path=$this->runtimeEnvPath();
        if($path==='' || !is_file($path) || !is_readable($path))return $this->runtimeEnv=[];
        $values=[];
        foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
            $line=trim($line);
            if($line==='' || str_starts_with($line,'#'))continue;
            if(str_starts_with($line,'export '))$line=trim(substr($line,7));
            if(!str_contains($line,'='))continue;
            [$name,$value]=array_map('trim',explode('=',$line,2));
            if(preg_match('/^[A-Z][A-Z0-9_]*$/D',$name)!==1)continue;
            if(strlen($value)>=2 && (($value[0]==='"' && $value[-1]==='"') || ($value[0]==="'" && $value[-1]==="'"))){
                $value=substr($value,1,-1);
            }
            $values[$name]=$value;
        }
        return $this->runtimeEnv=$values;
    }

    private function runtimeEnvPath(): string
    {
        $override=trim((string)($this->override['AMAZON_RETURNS_ENV_FILE']??''));
        if($override!=='')return $override;
        $configured=getenv('AMAZON_RETURNS_ENV_FILE');
        if(is_string($configured) && trim($configured)!=='')return trim($configured);
        if(PHP_SAPI==='cli')return '';
        return '/home/ubuntu/amazon-returns-deploy/shared/.env';
    }

    public function first(string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = $this->get($key);
            if ($value !== '') return $value;
        }
        return '';
    }

    private function aiSecret(string ...$keys): string
    {
        foreach($keys as $key){$value=$this->get($key);if($value!=='')return $value;}
        $path=$this->get('AMAZON_RETURNS_AI_ENV_FILE','/home/ubuntu/amazon-returns-deploy/ai-secrets.env');
        if($path===''||!is_file($path)||!is_readable($path))return '';
        $wanted=array_fill_keys($keys,true);
        foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){
            if(str_starts_with(ltrim($line),'#')||!str_contains($line,'='))continue;
            [$key,$value]=array_map('trim',explode('=',$line,2));
            if(isset($wanted[$key])&&$value!=='')return $value;
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
