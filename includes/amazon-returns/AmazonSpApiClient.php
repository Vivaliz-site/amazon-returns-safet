<?php
declare(strict_types=1);

require_once __DIR__ . '/Config.php';

final class SvAmazonSpApiClient
{
    private SvAmazonReturnsConfig $config;
    /** @var callable(string,string,array<string,string>,?string):array<string,mixed> */
    private $http;
    private string $endpoint;
    private string $marketplaceId;
    private ?string $accessToken = null;

    public function __construct(?SvAmazonReturnsConfig $config = null, ?callable $http = null)
    {
        $this->config = $config ?? new SvAmazonReturnsConfig();
        $this->endpoint = rtrim($this->config->first('AMAZON_SP_API_ENDPOINT','SP_API_ENDPOINT'), '/');
        if ($this->endpoint === '') $this->endpoint = 'https://sellingpartnerapi-na.amazon.com';
        $this->marketplaceId = $this->config->first('AMAZON_MARKETPLACE_ID','AMAZON_MARKETPLACE','SP_API_MARKETPLACE_ID');
        $this->http = $http ?? [$this, 'httpRequest'];

        foreach (['AMAZON_LWA_CLIENT_ID','AMAZON_LWA_CLIENT_SECRET','AMAZON_LWA_REFRESH_TOKEN'] as $key) {
            if ($this->config->get($key) === '') throw new RuntimeException('Amazon LWA credentials incomplete.');
        }
    }

    public function marketplaceId(): string
    {
        if ($this->marketplaceId !== '') return $this->marketplaceId;
        return $this->marketplaceId = $this->discoverMarketplaceId();
    }

    /** @return array{status:int,request_id:string,data:array<string,mixed>} */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $method = strtoupper(trim($method));
        ksort($query, SORT_STRING);
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $url = $this->endpoint . $path . ($queryString !== '' ? '?' . $queryString : '');
        $headers = [
            'Content-Type'=>'application/json',
            'Accept'=>'application/json',
            'x-amz-access-token'=>$this->accessToken(),
            'user-agent'=>'AmazonReturnsSafet/1.0',
        ];
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $raw = ($this->http)($method, $url, $headers, $encoded);
        return [
            'status'=>(int)($raw['status'] ?? 0),
            'request_id'=>trim((string)($raw['request_id'] ?? '')),
            'data'=>is_array($raw['json'] ?? null) ? $raw['json'] : [],
        ];
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $body = http_build_query([
            'grant_type'=>'refresh_token',
            'refresh_token'=>$this->config->get('AMAZON_LWA_REFRESH_TOKEN'),
            'client_id'=>$this->config->get('AMAZON_LWA_CLIENT_ID'),
            'client_secret'=>$this->config->get('AMAZON_LWA_CLIENT_SECRET'),
        ], '', '&', PHP_QUERY_RFC3986);
        $raw = ($this->http)(
            'POST',
            'https://api.amazon.com/auth/o2/token',
            ['Content-Type'=>'application/x-www-form-urlencoded','Accept'=>'application/json'],
            $body
        );
        $token = trim((string)($raw['json']['access_token'] ?? ''));
        if ((int)($raw['status'] ?? 0) !== 200 || $token === '') {
            throw new RuntimeException('Amazon LWA token request failed.');
        }
        return $this->accessToken = $token;
    }

    private function discoverMarketplaceId(): string
    {
        $response = $this->request('GET', '/sellers/v1/marketplaceParticipations');
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Amazon marketplace discovery failed.');
        }
        $payload = $response['data']['payload'] ?? $response['data'];
        if (!is_array($payload)) throw new RuntimeException('Amazon marketplace discovery returned invalid data.');
        $fallback = '';
        foreach ($payload as $entry) {
            if (!is_array($entry)) continue;
            $marketplace = is_array($entry['marketplace'] ?? null) ? $entry['marketplace'] : [];
            $participation = is_array($entry['participation'] ?? null) ? $entry['participation'] : [];
            $id = trim((string)($marketplace['id'] ?? $marketplace['marketplaceId'] ?? ''));
            if ($id === '') continue;
            if ($fallback === '') $fallback = $id;
            $country = strtoupper(trim((string)($marketplace['countryCode'] ?? '')));
            $participating = !array_key_exists('isParticipating',$participation) || (bool)$participation['isParticipating'];
            $suspended = (bool)($participation['hasSuspendedListings'] ?? false);
            if ($country === 'BR' && $participating && !$suspended) return $id;
        }
        if ($fallback !== '') return $fallback;
        throw new RuntimeException('No eligible Amazon marketplace was found.');
    }

    /** @return array{status:int,request_id:string,json:array<string,mixed>} */
    private function httpRequest(string $method, string $url, array $headers, ?string $body): array
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to initialize Amazon HTTP request.');
        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $name=>$value) $headerLines[] = $name . ': ' . $value;
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>60,
            CURLOPT_CONNECTTIMEOUT=>15,
            CURLOPT_HTTPHEADER=>$headerLines,
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function($curl,string $line) use (&$responseHeaders): int {
                $pos = strpos($line, ':');
                if ($pos !== false) $responseHeaders[strtolower(trim(substr($line,0,$pos)))] = trim(substr($line,$pos+1));
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if (!is_string($raw)) throw new RuntimeException('Amazon HTTP transport failed: ' . $error);
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = [];
        $requestId = trim((string)($responseHeaders['x-amzn-requestid'] ?? $responseHeaders['x-amz-request-id'] ?? ''));
        return ['status'=>$status,'request_id'=>$requestId,'json'=>$json];
    }
}
