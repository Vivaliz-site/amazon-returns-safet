<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

final class SvAmazonGmailApiClient
{
    private const READ_RATE_LIMIT_RETRIES = 3;
    /** @var callable(string,string,array<string,string>,?array):array<string,mixed> */
    private $transport;
    /** @var callable(int):void */
    private $sleep;
    /** @var callable():int */
    private $jitter;
    private ?string $accessToken = null;

    /**
     * @param callable(string,string,array<string,string>,?array):array<string,mixed>|null $transport
     * @param callable(int):void|null $sleep
     * @param callable():int|null $jitter
     */
    public function __construct(
        private ?SvAmazonReturnsConfig $config = null,
        ?callable $transport = null,
        ?callable $sleep = null,
        ?callable $jitter = null
    ) {
        $this->config ??= new SvAmazonReturnsConfig();
        $this->transport = $transport ?? [$this, 'httpJson'];
        $this->sleep = $sleep ?? static function(int $microseconds): void { usleep($microseconds); };
        $this->jitter = $jitter ?? static fn(): int => random_int(0, 999999);
    }

    /** @return array{message_id:string,thread_id:string} */
    public function send(string $to, string $subject, string $body): array
    {
        return $this->sendMessage($to, $subject, $body, null, null, null);
    }

    /** @return array{message_id:string,thread_id:string} */
    public function sendOnce(string $to, string $subject, string $body, string $idempotencyKey): array
    {
        $key = strtolower(trim($idempotencyKey));
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) throw new InvalidArgumentException('Invalid Gmail idempotency key.');
        $messageId = 'amazon-returns-' . $key . '@returns.shopvivaliz.com.br';
        $found = $this->request('GET', '/messages', ['q'=>'in:sent rfc822msgid:' . $messageId, 'maxResults'=>'1']);
        $existing = is_array($found['messages'][0] ?? null) ? $found['messages'][0] : null;
        if ($existing !== null && trim((string)($existing['id'] ?? '')) !== '') {
            return ['message_id'=>trim((string)$existing['id']), 'thread_id'=>trim((string)($existing['threadId'] ?? ''))];
        }
        return $this->sendMessage($to, $subject, $body, $messageId, null, null);
    }

    /** @return array{message_id:string,thread_id:string} */
    public function sendReplyOnce(string $to,string $subject,string $body,string $threadId,string $inReplyTo,string $idempotencyKey): array { $key=strtolower(trim($idempotencyKey)); if(preg_match("/^[a-f0-9]{64}$/",$key)!==1) throw new InvalidArgumentException("Invalid Gmail idempotency key."); if(trim($threadId)==="") throw new InvalidArgumentException("Gmail thread ID is required."); $messageId="amazon-returns-".$key."@returns.shopvivaliz.com.br"; $found=$this->request("GET","/messages",["q"=>"in:sent rfc822msgid:".$messageId,"maxResults"=>"1"]); $existing=is_array($found["messages"][0] ?? null)?$found["messages"][0]:null; if($existing!==null && trim((string)($existing["id"] ?? ""))!=="") return ["message_id"=>trim((string)$existing["id"]),"thread_id"=>trim((string)($existing["threadId"] ?? $threadId))]; return $this->sendMessage($to,$subject,$body,$messageId,trim($threadId),trim($inReplyTo)); }

    private function sendMessage(string $to, string $subject, string $body, ?string $messageId, ?string $threadId, ?string $inReplyTo): array
    {
        $to = trim($to);
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? $subject);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || $subject === '' || trim($body) === '') {
            throw new InvalidArgumentException('Invalid Gmail outbound message.');
        }
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = ["To: {$to}", "Subject: {$encodedSubject}", 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'];
        if ($messageId !== null) $headers[] = 'Message-ID: <' . $messageId . '>';
        if ($inReplyTo !== null && trim($inReplyTo) !== "") { $headers[] = "In-Reply-To: " . trim($inReplyTo); $headers[] = "References: " . trim($inReplyTo); }
        $mime = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
        $response = ($this->transport)(
            'POST',
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            ['Authorization'=>'Bearer ' . $this->token(), 'Accept'=>'application/json'],
            $threadId !== null && trim($threadId) !== '' ? ['raw'=>$raw,'threadId'=>trim($threadId)] : ['raw'=>$raw]
        );
        $status = (int)($response['status'] ?? 0);
        $json = is_array($response['json'] ?? null) ? $response['json'] : [];
        if ($status < 200 || $status >= 300) throw new RuntimeException('Gmail API HTTP ' . $status . '.');
        $sentId = trim((string)($json['id'] ?? ''));
        if ($sentId === '') throw new RuntimeException('Gmail send did not return message ID.');
        return ['message_id'=>$sentId,'thread_id'=>trim((string)($json['threadId'] ?? ''))];
    }

    /** @return array{messages:list<array<string,mixed>>,cursor:string,recovered_cursor:bool} */
    public function pull(?string $cursor, int $bootstrapDays = 2): array
    {
        $bootstrapDays = max(1, min(30, $bootstrapDays));
        $profile = $this->request('GET', '/profile');
        $nextCursor = trim((string)($profile['historyId'] ?? ''));
        if ($nextCursor === '') throw new RuntimeException('Gmail profile did not return historyId.');

        $recovered = false;
        try {
            $ids = $cursor !== null && trim($cursor) !== ''
                ? $this->historyMessageIds(trim($cursor))
                : $this->bootstrapMessageIds($bootstrapDays);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'HTTP 404')) throw $e;
            $ids = $this->bootstrapMessageIds(min(7, $bootstrapDays + 5));
            $recovered = true;
        }

        $messages = [];
        foreach (array_values(array_unique($ids)) as $id) {
            try {
                $message = $this->request('GET', '/messages/' . rawurlencode($id), ['format'=>'full']);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'HTTP 404')) continue;
                throw $e;
            }
            $messages[] = $this->normalizeMessage($message);
        }
        return ['messages'=>$messages,'cursor'=>$nextCursor,'recovered_cursor'=>$recovered];
    }

    /**
     * Bounded Gmail history batch: at most one /history page and at most
     * $messageLimit unique messages.get calls per invocation. The returned
     * checkpoint_cursor only ever advances through whole history records
     * that were fully fetched, so a caller can safely persist it even when
     * has_more is true.
     *
     * @return array{messages:list<array<string,mixed>>,checkpoint_cursor:string,mailbox_history_id:string,has_more:bool,recovered_cursor:bool}
     */
    public function pullIncrementalBatch(?string $cursor, int $historyPageSize, int $messageLimit): array
    {
        $historyPageSize = max(1, min(500, $historyPageSize));
        $messageLimit = max(1, $messageLimit);
        $cursor = $cursor !== null ? trim($cursor) : '';

        $profile = $this->request('GET', '/profile');
        $mailboxHistoryId = trim((string)($profile['historyId'] ?? ''));
        if ($mailboxHistoryId === '') throw new RuntimeException('Gmail profile did not return historyId.');

        if ($cursor === '') {
            return ['messages'=>[],'checkpoint_cursor'=>$mailboxHistoryId,'mailbox_history_id'=>$mailboxHistoryId,'has_more'=>false,'recovered_cursor'=>false];
        }

        try {
            $data = $this->request('GET', '/history', ['startHistoryId'=>$cursor,'historyTypes'=>'messageAdded','maxResults'=>(string)$historyPageSize]);
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'HTTP 404')) throw $e;
            $recoveryIds = array_values(array_unique($this->bootstrapMessageIds(7)));
            if (count($recoveryIds) > $messageLimit) {
                throw new RuntimeException('Gmail 404 recovery message set exceeds bounded message limit.');
            }
            $messages = $this->fetchMessagesByIds($recoveryIds);
            return ['messages'=>$messages,'checkpoint_cursor'=>$mailboxHistoryId,'mailbox_history_id'=>$mailboxHistoryId,'has_more'=>false,'recovered_cursor'=>true];
        }

        $records = array_values(array_filter($data['history'] ?? [], 'is_array'));
        $nextPageToken = isset($data['nextPageToken']) ? trim((string)$data['nextPageToken']) : '';

        $checkpoint = $cursor;
        $coveredIds = [];
        $seen = [];
        $truncated = false;

        foreach ($records as $record) {
            $recordId = trim((string)($record['id'] ?? ''));
            $recordIds = [];
            $recordSeen = [];
            foreach (($record['messagesAdded'] ?? []) as $added) {
                if (!is_array($added)) continue;
                $id = trim((string)($added['message']['id'] ?? ''));
                if ($id === '' || isset($seen[$id]) || isset($recordSeen[$id])) continue;
                $recordSeen[$id] = true;
                $recordIds[] = $id;
            }
            if (count($coveredIds) + count($recordIds) > $messageLimit) {
                if (count($coveredIds) === 0) {
                    throw new RuntimeException('Gmail history record exceeds bounded message limit.');
                }
                $truncated = true;
                break;
            }
            foreach ($recordIds as $id) { $seen[$id] = true; $coveredIds[] = $id; }
            if ($recordId !== '') $checkpoint = $recordId;
        }

        $hasMore = $truncated || $nextPageToken !== '';
        if (!$hasMore) $checkpoint = $mailboxHistoryId;

        $messages = $this->fetchMessagesByIds($coveredIds);

        return [
            'messages'=>$messages,
            'checkpoint_cursor'=>$checkpoint,
            'mailbox_history_id'=>$mailboxHistoryId,
            'has_more'=>$hasMore,
            'recovered_cursor'=>false,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function searchMessages(string $queryText, int $maxMessages = 500): array
    {
        $queryText = trim($queryText);
        if ($queryText === '') throw new InvalidArgumentException('Gmail search query cannot be empty.');
        $maxMessages = max(1, min(1000, $maxMessages));
        $ids = [];
        $pageToken = null;
        do {
            $remaining = $maxMessages - count($ids);
            if ($remaining <= 0) break;
            $query = ['q'=>$queryText,'maxResults'=>(string)min(500, $remaining)];
            if ($pageToken !== null) $query['pageToken'] = $pageToken;
            $data = $this->request('GET', '/messages', $query);
            foreach (($data['messages'] ?? []) as $message) {
                if (!is_array($message)) continue;
                $id = trim((string)($message['id'] ?? ''));
                if ($id !== '') $ids[] = $id;
                if (count($ids) >= $maxMessages) break;
            }
            $pageToken = isset($data['nextPageToken']) ? trim((string)$data['nextPageToken']) : null;
            if ($pageToken === '') $pageToken = null;
        } while ($pageToken !== null && count($ids) < $maxMessages);

        $messages = [];
        foreach (array_values(array_unique($ids)) as $id) {
            try {
                $message = $this->request('GET', '/messages/' . rawurlencode($id), ['format'=>'full']);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'HTTP 404')) continue;
                throw $e;
            }
            $messages[] = $this->normalizeMessage($message);
        }
        return $messages;
    }
    /** @param list<string> $ids @return list<array<string,mixed>> */
    private function fetchMessagesByIds(array $ids): array
    {
        $messages = [];
        foreach (array_values(array_unique($ids)) as $id) {
            try {
                $message = $this->request('GET', '/messages/' . rawurlencode($id), ['format'=>'full']);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'HTTP 404')) continue;
                throw $e;
            }
            $messages[] = $this->normalizeMessage($message);
        }
        return $messages;
    }

    /** @return list<string> */
    private function historyMessageIds(string $cursor): array
    {
        $ids = [];
        $pageToken = null;
        do {
            $query = ['startHistoryId'=>$cursor,'historyTypes'=>'messageAdded','maxResults'=>'500'];
            if ($pageToken !== null) $query['pageToken'] = $pageToken;
            $data = $this->request('GET', '/history', $query);
            foreach (($data['history'] ?? []) as $history) {
                if (!is_array($history)) continue;
                foreach (($history['messagesAdded'] ?? []) as $added) {
                    if (!is_array($added)) continue;
                    $id = trim((string)($added['message']['id'] ?? ''));
                    if ($id !== '') $ids[] = $id;
                }
            }
            $pageToken = isset($data['nextPageToken']) ? trim((string)$data['nextPageToken']) : null;
            if ($pageToken === '') $pageToken = null;
        } while ($pageToken !== null);
        return $ids;
    }

    /** @return list<string> */
    private function bootstrapMessageIds(int $days): array
    {
        $ids = [];
        $pageToken = null;
        $queryText = 'newer_than:' . $days . 'd (from:donotreply@amazon.com OR from:amazon.com.br OR from:Safe-T-Review@amazon.com)';
        do {
            $query = ['q'=>$queryText,'maxResults'=>'500'];
            if ($pageToken !== null) $query['pageToken'] = $pageToken;
            $data = $this->request('GET', '/messages', $query);
            foreach (($data['messages'] ?? []) as $message) {
                if (!is_array($message)) continue;
                $id = trim((string)($message['id'] ?? ''));
                if ($id !== '') $ids[] = $id;
            }
            $pageToken = isset($data['nextPageToken']) ? trim((string)$data['nextPageToken']) : null;
            if ($pageToken === '') $pageToken = null;
        } while ($pageToken !== null);
        return $ids;
    }

    /** @return array<string,mixed> */
    private function normalizeMessage(array $message): array
    {
        $payload = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        $headers = [];
        foreach (($payload['headers'] ?? []) as $header) {
            if (!is_array($header)) continue;
            $name = strtolower(trim((string)($header['name'] ?? '')));
            if ($name !== '') $headers[$name] = trim((string)($header['value'] ?? ''));
        }
        $receivedAt = null;
        $internalDate = trim((string)($message['internalDate'] ?? ''));
        if ($internalDate !== '' && ctype_digit($internalDate)) {
            $seconds = (int)floor(((int)$internalDate) / 1000);
            $receivedAt = gmdate('c', $seconds);
        }
        return [
            'message_id'=>trim((string)($message['id'] ?? '')),
            'thread_id'=>trim((string)($message['threadId'] ?? '')),
            'rfc_message_id'=>$headers['message-id'] ?? '',
            'from'=>$headers['from'] ?? '',
            'subject'=>$headers['subject'] ?? '',
            'received_at'=>$receivedAt,
            'body_text'=>$this->extractText($payload),
            'snippet'=>trim((string)($message['snippet'] ?? '')),
            'labels'=>is_array($message['labelIds'] ?? null) ? array_values(array_map('strval',$message['labelIds'])) : [],
        ];
    }

    private function extractText(array $part): string
    {
        $mime = strtolower(trim((string)($part['mimeType'] ?? '')));
        $data = trim((string)($part['body']['data'] ?? ''));
        if ($data !== '' && ($mime === 'text/plain' || $mime === 'text/html' || $mime === '')) {
            $decoded = $this->decodeBase64Url($data);
            return $mime === 'text/html'
                ? trim(html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : trim($decoded);
        }
        $htmlFallback = '';
        foreach (($part['parts'] ?? []) as $child) {
            if (!is_array($child)) continue;
            $text = $this->extractText($child);
            if ($text === '') continue;
            if (strtolower((string)($child['mimeType'] ?? '')) === 'text/plain') return $text;
            if ($htmlFallback === '') $htmlFallback = $text;
        }
        return $htmlFallback;
    }

    private function decodeBase64Url(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $padding = strlen($padded) % 4;
        if ($padding > 0) $padded .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode($padded, true);
        return is_string($decoded) ? $decoded : '';
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, array $query = []): array
    {
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me' . $path;
        if ($query !== []) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $attempt=0;
        do {
            $response = ($this->transport)(
                $method,
                $url,
                ['Authorization'=>'Bearer ' . $this->token(), 'Accept'=>'application/json'],
                null
            );
            $status = (int)($response['status'] ?? 0);
            $json = is_array($response['json'] ?? null) ? $response['json'] : [];
            if ($status >= 200 && $status < 300) return $json;
            $reason = trim((string)($json['error']['errors'][0]['reason'] ?? $json['error']['status'] ?? ''));
            $reason = preg_replace('/[^A-Za-z0-9_.-]/', '', $reason) ?? '';
            if ($this->shouldRetryRead($method,$status,$reason) && $attempt < self::READ_RATE_LIMIT_RETRIES) {
                $baseSeconds=1 << $attempt;
                $jitter=max(0,min(999999,(int)($this->jitter)()));
                ($this->sleep)($baseSeconds*1000000+$jitter);
                $attempt++;
                continue;
            }
            $suffix = $reason !== '' ? ' reason=' . substr($reason, 0, 80) : '';
            throw new RuntimeException('Gmail API HTTP ' . $status . $suffix . '.');
        } while (true);
    }

    private function shouldRetryRead(string $method,int $status,string $reason): bool
    {
        if (strtoupper($method)!=='GET') return false;
        if ($status===429) return true;
        return $status===403 && in_array($reason,['rateLimitExceeded','userRateLimitExceeded'],true);
    }

    private function token(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $direct = $this->config->get('GMAIL_OAUTH_ACCESS_TOKEN');
        if ($direct !== '') return $this->accessToken = $direct;

        $clientId = $this->config->first('GMAIL_OAUTH_CLIENT_ID','GOOGLE_OAUTH_CLIENT_ID');
        $clientSecret = $this->config->first('GMAIL_OAUTH_CLIENT_SECRET','GOOGLE_OAUTH_CLIENT_SECRET');
        $refreshToken = $this->config->first('GMAIL_OAUTH_REFRESH_TOKEN','GOOGLE_OAUTH_REFRESH_TOKEN');
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException('Gmail OAuth credentials are incomplete.');
        }
        $response = $this->httpForm(
            'https://oauth2.googleapis.com/token',
            ['client_id'=>$clientId,'client_secret'=>$clientSecret,'refresh_token'=>$refreshToken,'grant_type'=>'refresh_token']
        );
        $token = trim((string)($response['access_token'] ?? ''));
        if ($token === '') throw new RuntimeException('Gmail OAuth did not return access_token.');
        return $this->accessToken = $token;
    }

    private static function oauthFailureCode(mixed $json): string
    {
        if (!is_array($json)) return '';
        $error = trim((string)($json['error'] ?? ''));
        $error = preg_replace('/[^A-Za-z0-9_.-]/', '', $error) ?? '';
        return substr($error, 0, 80);
    }

    /** @return array<string,mixed> */
    private function httpForm(string $url, array $fields): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Unable to initialize Gmail OAuth transport.');
        curl_setopt_array($ch, [
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>10,
            CURLOPT_TIMEOUT=>30,
            CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw)) throw new RuntimeException('Gmail OAuth transport failed: ' . $error);
        $json = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($json)) {
            $reason = self::oauthFailureCode($json);
            $suffix = $reason !== '' ? ' error=' . $reason : '';
            throw new RuntimeException('Gmail OAuth HTTP ' . $status . $suffix . '.');
        }
        return $json;
    }

    /** @return array<string,mixed> */
    private function httpJson(string $method, string $url, array $headers, ?array $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Unable to initialize Gmail API transport.');
        $headerLines=[]; foreach($headers as $name=>$value) $headerLines[]=$name . ': ' . $value;
        $options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$headerLines,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2];
        if ($body !== null) { $headerLines[]='Content-Type: application/json'; $options[CURLOPT_HTTPHEADER]=$headerLines; $options[CURLOPT_POSTFIELDS]=json_encode($body, JSON_THROW_ON_ERROR); }
        curl_setopt_array($ch,$options);
        $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $error=curl_error($ch); curl_close($ch);
        if (!is_string($raw)) throw new RuntimeException('Gmail API transport failed: ' . $error);
        $json=json_decode($raw,true);
        return ['status'=>$status,'json'=>is_array($json)?$json:[]];
    }
}
