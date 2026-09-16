<?php
declare(strict_types=1);

require_once __DIR__ . '/../workers/amazon-returns/daemon.php';

function gicSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}
function gicAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

/** Minimal fake PDO backing only the tables the catch-up path touches: source cursors and (when an
 *  event is actually produced) amazon_return_cases, so ingestion failures can be provoked deterministically. */
final class GmailCatchupPdo extends PDO
{
    /** @var array<string,array{cursor_value:string,metadata_json:?string,observed_at:string}> */
    public array $cursorRows = [];
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $writes = [];
    public bool $failCaseLookup = false;
    public int $claims = 0;
    public function beginTransaction(): bool { $this->claims++; return true; }
    public function commit(): bool { return true; }
    public function rollBack(): bool { return true; }


    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new GmailCatchupStatement($this, $query);
    }
}

final class GmailCatchupStatement extends PDOStatement
{
    private array $params = [];

    public function __construct(private GmailCatchupPdo $db, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if ($this->db->failCaseLookup && str_contains($this->sql, 'amazon_return_cases')) {
            throw new PDOException('GMAIL_CATCHUP_TEST_INGEST_FAILURE');
        }
        if (str_contains($this->sql, 'INSERT INTO amazon_return_source_cursors')) {
            $key = ($this->params[':source'] ?? '') . '|' . ($this->params[':cursor_key'] ?? '');
            $this->db->cursorRows[$key] = [
                'cursor_value' => (string)($this->params[':cursor_value'] ?? ''),
                'metadata_json' => is_string($this->params[':metadata_json'] ?? null) ? $this->params[':metadata_json'] : null,
                'observed_at' => '2026-09-16 00:00:00',
            ];
            $this->db->writes[] = ['sql' => $this->sql, 'params' => $this->params];
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if (str_contains($this->sql, 'SELECT cursor_value')) {
            $key = ($this->params[':source'] ?? '') . '|' . ($this->params[':cursor_key'] ?? '');
            return $this->db->cursorRows[$key] ?? false;
        }
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function fetchColumn(int $column = 0): mixed { return false; }
    public function rowCount(): int { return 1; }
}

final class GmailCatchupDaemon extends SvAmazonReturnsDaemon
{
    public function __construct(PDO $db, SvAmazonTenantContext $context, SvAmazonReturnsConfig $config, private SvAmazonGmailApiClient $fakeGmail)
    {
        parent::__construct($db, $context, $config);
    }

    protected function gmailClient(): SvAmazonGmailApiClient
    {
        return $this->fakeGmail;
    }
}

function gicConfig(): SvAmazonReturnsConfig
{
    return new SvAmazonReturnsConfig([
        'AMAZON_RETURNS_ENABLED' => '1',
        'AMAZON_RETURNS_MODE' => 'dry-run',
        'AMAZON_RETURNS_GMAIL_INGEST' => '1',
        'GMAIL_OAUTH_CLIENT_ID' => 'test',
        'GMAIL_OAUTH_CLIENT_SECRET' => 'test',
        'GMAIL_OAUTH_REFRESH_TOKEN' => 'test',
        'GMAIL_OAUTH_ACCESS_TOKEN' => 'test-token',
    ]);
}

function gicMessageJson(string $id, string $subject, string $from = 'Amazon <donotreply@amazon.com>'): array
{
    return [
        'id' => $id, 'threadId' => 'thread-' . $id, 'internalDate' => '1788283827000',
        'payload' => ['headers' => [
            ['name' => 'From', 'value' => $from],
            ['name' => 'Subject', 'value' => $subject],
        ], 'mimeType' => 'text/plain', 'body' => ['data' => rtrim(strtr(base64_encode('Corpo do email.'), '+/', '-_'), '=')]],
    ];
}

const GIC_NEUTRAL_SUBJECT = 'Assunto neutro sem padrao reconhecido';
const GIC_REFUND_SUBJECT = 'Reembolso de 10.50 BRL iniciado para o pedido 701-0000000-0000000';

function gicSeedCursor(GmailCatchupPdo $pdo, string $value): void
{
    $pdo->cursorRows['GMAIL|history_id_v2'] = [
        'cursor_value' => $value, 'metadata_json' => null, 'observed_at' => '2026-09-16 00:00:00',
    ];
}

function gicCursorValue(GmailCatchupPdo $pdo): ?string
{
    return $pdo->cursorRows['GMAIL|history_id_v2']['cursor_value'] ?? null;
}

// --- C1: successful bounded batch persists checkpoint after ingestion (catch-up mode, has_more=false) ---
$pdo1 = new GmailCatchupPdo();
gicSeedCursor($pdo1, '100');
$transport1 = static function (string $method, string $url, array $headers, ?array $body = null): array {
    if (str_contains($url, '/profile')) return ['status' => 200, 'json' => ['historyId' => '150']];
    if (str_contains($url, '/history?')) {
        return ['status' => 200, 'json' => ['history' => [
            ['id' => '150', 'messagesAdded' => [['message' => ['id' => 'm-c1']]]],
        ]]];
    }
    if (str_contains($url, '/messages/m-c1')) return ['status' => 200, 'json' => gicMessageJson('m-c1', GIC_NEUTRAL_SUBJECT)];
    throw new RuntimeException('Unexpected URL in C1: ' . $url);
};
$gmail1 = new SvAmazonGmailApiClient(gicConfig(), $transport1);
$daemon1 = new GmailCatchupDaemon($pdo1, new SvAmazonTenantContext(1, 1), gicConfig(), $gmail1);
$result1 = (new ReflectionMethod($daemon1, 'runGmail'))->invoke($daemon1);
gicSame('OK', $result1['status'] ?? null, 'C1: successful catch-up batch must report OK.');
gicSame(true, $result1['gmail_catchup'] ?? null, 'C1: an existing cursor must use bounded catch-up, not legacy bootstrap.');
gicSame(false, $result1['has_more'] ?? null, 'C1: a drained final page has no remaining history.');
gicSame(true, $result1['checkpoint_advanced'] ?? null, 'C1: checkpoint must advance past the prior cursor.');
gicSame(1, $result1['messages'] ?? null, 'C1: batch message count must be reported.');
gicSame(0, $result1['events'] ?? null, 'C1: neutral-subject message produces no domain events.');
gicSame('150', gicCursorValue($pdo1), 'C1: persisted checkpoint must equal the batch checkpoint_cursor.');

// --- C2: no history cursor preserves legacy bootstrap behavior ---
$pdo2 = new GmailCatchupPdo();
$transport2 = static function (string $method, string $url, array $headers, ?array $body = null): array {
    if (str_contains($url, '/profile')) return ['status' => 200, 'json' => ['historyId' => '500']];
    if (str_contains($url, '/messages?')) return ['status' => 200, 'json' => ['messages' => [['id' => 'm-boot']]]];
    if (str_contains($url, '/messages/m-boot')) return ['status' => 200, 'json' => gicMessageJson('m-boot', GIC_NEUTRAL_SUBJECT)];
    throw new RuntimeException('Unexpected URL in C2: ' . $url);
};
$gmail2 = new SvAmazonGmailApiClient(gicConfig(), $transport2);
$daemon2 = new GmailCatchupDaemon($pdo2, new SvAmazonTenantContext(1, 1), gicConfig(), $gmail2);
$result2 = (new ReflectionMethod($daemon2, 'runGmail'))->invoke($daemon2);
gicSame(false, $result2['gmail_catchup'] ?? null, 'C2: no history cursor must preserve the legacy bootstrap pull.');
gicSame(false, $result2['has_more'] ?? null, 'C2: a one-shot bootstrap pull never reports has_more.');
gicSame(true, $result2['checkpoint_advanced'] ?? null, 'C2: bootstrap must still persist an initial checkpoint.');
gicSame('500', gicCursorValue($pdo2), 'C2: bootstrap checkpoint must equal the mailbox history id.');

// --- C3: has_more=true is reported and the runtime schedules Gmail again in five minutes ---
$pdo3 = new GmailCatchupPdo();
gicSeedCursor($pdo3, '100');
$transport3 = static function (string $method, string $url, array $headers, ?array $body = null): array {
    if (str_contains($url, '/profile')) return ['status' => 200, 'json' => ['historyId' => '500']];
    if (str_contains($url, '/history?')) {
        return ['status' => 200, 'json' => [
            'history' => [['id' => '160', 'messagesAdded' => [['message' => ['id' => 'm-c3']]]]],
            'nextPageToken' => 'page-2',
        ]];
    }
    if (str_contains($url, '/messages/m-c3')) return ['status' => 200, 'json' => gicMessageJson('m-c3', GIC_NEUTRAL_SUBJECT)];
    throw new RuntimeException('Unexpected URL in C3: ' . $url);
};
$gmail3 = new SvAmazonGmailApiClient(gicConfig(), $transport3);
$daemon3 = new GmailCatchupDaemon($pdo3, new SvAmazonTenantContext(1, 1), gicConfig(), $gmail3);
$result3 = (new ReflectionMethod($daemon3, 'runGmail'))->invoke($daemon3);
gicSame(true, $result3['has_more'] ?? null, 'C3: a Gmail nextPageToken must surface as has_more=true.');
gicSame('160', gicCursorValue($pdo3), 'C3: checkpoint must still advance to the last fully covered record while more remains.');
gicSame(
    300,
    SvAmazonReturnsRuntime::gmailCatchupRetryDelaySeconds('gmail', $result3),
    'C3: has_more=true must schedule the Gmail task again in five minutes.'
);

// --- has_more=false must not force a retry delay (regular 12h cadence resumes) ---
gicSame(
    null,
    SvAmazonReturnsRuntime::gmailCatchupRetryDelaySeconds('gmail', $result1),
    'has_more=false must leave the regular Gmail cadence untouched.'
);
gicSame(
    null,
    SvAmazonReturnsRuntime::gmailCatchupRetryDelaySeconds('gmail_refund_reconciliation', ['has_more' => true]),
    'Only the gmail catch-up task itself schedules the five-minute continuation.'
);

// --- C4: a thrown ingest/persist error must leave the prior cursor authoritative ---
$pdo4 = new GmailCatchupPdo();
gicSeedCursor($pdo4, '300');
$pdo4->failCaseLookup = true;
$transport4 = static function (string $method, string $url, array $headers, ?array $body = null): array {
    if (str_contains($url, '/profile')) return ['status' => 200, 'json' => ['historyId' => '500']];
    if (str_contains($url, '/history?')) {
        return ['status' => 200, 'json' => ['history' => [
            ['id' => '400', 'messagesAdded' => [['message' => ['id' => 'm-c4']]]],
        ]]];
    }
    if (str_contains($url, '/messages/m-c4')) return ['status' => 200, 'json' => gicMessageJson('m-c4', GIC_REFUND_SUBJECT)];
    throw new RuntimeException('Unexpected URL in C4: ' . $url);
};
$gmail4 = new SvAmazonGmailApiClient(gicConfig(), $transport4);
$daemon4 = new GmailCatchupDaemon($pdo4, new SvAmazonTenantContext(1, 1), gicConfig(), $gmail4);
$threw = false;
try {
    (new ReflectionMethod($daemon4, 'runGmail'))->invoke($daemon4);
} catch (Throwable $e) {
    $threw = true;
    gicAssert(str_contains((string)$e->getMessage(), 'GMAIL_CATCHUP_TEST_INGEST_FAILURE') || $e->getPrevious() !== null, 'C4: ingest failure must surface the underlying cause.');
}
gicAssert($threw, 'C4: an ingest/persist failure must propagate instead of silently succeeding.');
gicSame('300', gicCursorValue($pdo4), 'C4: the prior cursor must remain authoritative after a failed ingest.');
gicSame([], $pdo4->writes, 'C4: no checkpoint write may occur when ingestion fails.');

// --- C5: repeated batches advance the checkpoint monotonically ---
$pdo5 = new GmailCatchupPdo();
gicSeedCursor($pdo5, '100');
$transport5 = static function (string $method, string $url, array $headers, ?array $body = null): array {
    if (str_contains($url, '/profile')) return ['status' => 200, 'json' => ['historyId' => '500']];
    if (str_contains($url, 'startHistoryId=100')) {
        return ['status' => 200, 'json' => [
            'history' => [['id' => '160', 'messagesAdded' => [['message' => ['id' => 'm-c5a']]]]],
            'nextPageToken' => 'page-2',
        ]];
    }
    if (str_contains($url, 'startHistoryId=160')) {
        return ['status' => 200, 'json' => [
            'history' => [['id' => '500', 'messagesAdded' => [['message' => ['id' => 'm-c5b']]]]],
        ]];
    }
    if (str_contains($url, '/messages/m-c5a')) return ['status' => 200, 'json' => gicMessageJson('m-c5a', GIC_NEUTRAL_SUBJECT)];
    if (str_contains($url, '/messages/m-c5b')) return ['status' => 200, 'json' => gicMessageJson('m-c5b', GIC_NEUTRAL_SUBJECT)];
    throw new RuntimeException('Unexpected URL in C5: ' . $url);
};
$gmail5 = new SvAmazonGmailApiClient(gicConfig(), $transport5);
$daemon5 = new GmailCatchupDaemon($pdo5, new SvAmazonTenantContext(1, 1), gicConfig(), $gmail5);
$pass1 = (new ReflectionMethod($daemon5, 'runGmail'))->invoke($daemon5);
gicSame(true, $pass1['has_more'] ?? null, 'C5 pass 1: first bounded page must report more history remaining.');
gicSame('160', gicCursorValue($pdo5), 'C5 pass 1: checkpoint must advance to the first covered record.');
$pass2 = (new ReflectionMethod($daemon5, 'runGmail'))->invoke($daemon5);
gicSame(false, $pass2['has_more'] ?? null, 'C5 pass 2: draining the final page must end the catch-up pass.');
gicSame('500', gicCursorValue($pdo5), 'C5 pass 2: checkpoint must advance monotonically to the current mailbox position.');

// --- Structural: the daemon must actually apply the catch-up retry delay it exposes ---
$daemonSource = (string)file_get_contents(__DIR__ . '/../workers/amazon-returns/daemon.php');
gicAssert(str_contains($daemonSource, 'gmailCatchupRetryDelaySeconds'), 'Daemon must apply the bounded Gmail catch-up continuation delay.');

// Production write gates enabled: incomplete batches must never even claim email jobs.
$profile = tempnam(sys_get_temp_dir(), 'gic-write-profile-');
try {
    file_put_contents($profile, json_encode(['version'=>'task3-test', 'SAFE_T_EMAIL_REVIEW'=>true, 'SAFE_T_EMAIL_REPLY'=>true, 'SAFE_T_SUBMIT'=>false, 'SAFE_T_APPEAL'=>false, 'SELLER_SUPPORT_OPEN'=>false, 'SELLER_SUPPORT_UPDATE'=>false]));
    $config = new SvAmazonReturnsConfig([
        'AMAZON_RETURNS_ENABLED'=>'1', 'AMAZON_RETURNS_MODE'=>'production',
        'AMAZON_RETURNS_GMAIL_INGEST'=>'1', 'AMAZON_RETURNS_WRITE_PROFILE_FILE'=>$profile,
        'GMAIL_OAUTH_CLIENT_ID'=>'test', 'GMAIL_OAUTH_CLIENT_SECRET'=>'test',
        'GMAIL_OAUTH_REFRESH_TOKEN'=>'test', 'GMAIL_OAUTH_ACCESS_TOKEN'=>'test-token',
    ]);
    gicAssert($config->externalWriteAllowed('SAFE_T_EMAIL_REPLY'), 'Fixture must enable email writes.');
    foreach ([true, false] as $incomplete) {
        $db = new GmailCatchupPdo();
        gicSeedCursor($db, '100');
        $methods = [];
        $transport = static function ($method, $url, $headers, $body=null) use (&$methods, $incomplete, $transport3, $transport1) {
            $methods[] = $method;
            return ($incomplete ? $transport3 : $transport1)($method, $url, $headers, $body);
        };
        $daemon = new GmailCatchupDaemon($db, new SvAmazonTenantContext(1,1), $config, new SvAmazonGmailApiClient($config, $transport));
        $result = (new ReflectionMethod($daemon, 'runGmail'))->invoke($daemon);
        gicSame($incomplete ? 0 : 1, $db->claims, 'Incomplete Gmail must not claim email outbox; complete Gmail preserves claiming.');
        gicSame([], array_values(array_filter($methods, static fn($m)=>$m!=='GET')), 'Catch-up must not send email.');
        if ($incomplete) gicSame('GMAIL_CATCHUP_INCOMPLETE', $result['reason']??null, 'Safe skip reason must be explicit.');
    }
} finally { unlink($profile); }

echo "gmail-incremental-catchup-test: OK\n";
