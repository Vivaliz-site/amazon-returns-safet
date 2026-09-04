<?php

declare(strict_types=1);

final class SvAmazonSellerCentralWorker
{
    private const KINDS = ['SAFE_T_READ','SAFE_T_SUBMIT','SAFE_T_APPEAL','SELLER_SUPPORT_READ','SELLER_SUPPORT_OPEN','SELLER_SUPPORT_UPDATE'];
    private const STATUSES = ['ACCEPTED','BLOCKED_UNTIL','ALREADY_EXISTS','AUTH_REQUIRED','HUMAN_CHALLENGE','UI_DRIFT','NOT_FOUND','FAILED'];

    /** @param callable(array<string,mixed>):array<string,mixed>|null $runner */
    public function execute(array $outboxRow, ?callable $runner = null): array
    {
        $kind = strtoupper(trim((string)($outboxRow['kind'] ?? '')));
        if (!in_array($kind, self::KINDS, true)) throw new InvalidArgumentException('Unsupported Seller Central operation.');
        $payload = is_array($outboxRow['payload'] ?? null) ? $outboxRow['payload'] : [];
        $payload['action'] = $kind;
        $result = $runner ? $runner($payload) : $this->runNodeAdapter($payload);
        $status = strtoupper(trim((string)($result['status'] ?? '')));
        if (!in_array($status, self::STATUSES, true)) throw new RuntimeException('Invalid Seller Central adapter status.');
        if (($result['submitted'] ?? false) === true && ($result['external_id'] ?? null) === null) {
            throw new RuntimeException('A Seller Central write cannot be accepted without a read-back external ID.');
        }
        return $result;
    }

    private function runNodeAdapter(array $payload): array
    {
        $script = realpath(__DIR__ . '/../../scripts/amazon-returns/seller-central-adapter.mjs');
        if ($script === false) throw new RuntimeException('Seller Central adapter script missing.');
        $process = proc_open(['node', $script], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, dirname($script));
        if (!is_resource($process)) throw new RuntimeException('Unable to start Seller Central adapter.');
        fwrite($pipes[0], json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException('Seller Central adapter failed: ' . mb_substr(trim($stderr), 0, 500, 'UTF-8'));
        $result = json_decode((string)$stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new RuntimeException('Seller Central adapter returned invalid payload.');
        return $result;
    }
}
