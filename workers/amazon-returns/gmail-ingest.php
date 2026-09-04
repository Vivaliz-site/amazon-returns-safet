<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/amazon-returns/GmailParser.php';

final class SvAmazonGmailIngestor
{
    public function __construct(private ?SvAmazonGmailParser $parser = null)
    {
        $this->parser ??= new SvAmazonGmailParser();
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param callable(array<string,mixed>):int $append
     * @return array{messages:int,events:int,event_ids:list<int>,cursor:string}
     */
    public function ingest(array $messages, callable $append, string $cursor): array
    {
        $eventIds = [];
        $events = 0;
        foreach ($messages as $message) {
            if (!is_array($message)) continue;
            foreach ($this->parser->parse($message) as $event) {
                $eventIds[] = (int)$append($event);
                $events++;
            }
        }
        return [
            'messages' => count($messages),
            'events' => $events,
            'event_ids' => $eventIds,
            'cursor' => $cursor,
        ];
    }

    public static function saveCursor(PDO $db, string $cursorKey, string $cursorValue, array $metadata = []): void
    {
        $cursorKey = trim($cursorKey);
        $cursorValue = trim($cursorValue);
        if ($cursorKey === '' || $cursorValue === '') {
            throw new InvalidArgumentException('Gmail cursor key/value cannot be empty.');
        }
        $stmt = $db->prepare(
            'INSERT INTO amazon_return_source_cursors '
            . '(source, cursor_key, cursor_value, metadata_json, observed_at) '
            . 'VALUES (:source, :cursor_key, :cursor_value, :metadata_json, UTC_TIMESTAMP()) '
            . 'ON DUPLICATE KEY UPDATE cursor_value = VALUES(cursor_value), metadata_json = VALUES(metadata_json), observed_at = VALUES(observed_at)'
        );
        $stmt->execute([
            ':source' => 'GMAIL',
            ':cursor_key' => $cursorKey,
            ':cursor_value' => $cursorValue,
            ':metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public static function loadCursor(PDO $db, string $cursorKey): ?string
    {
        $stmt = $db->prepare('SELECT cursor_value FROM amazon_return_source_cursors WHERE source = :source AND cursor_key = :cursor_key LIMIT 1');
        $stmt->execute([':source' => 'GMAIL', ':cursor_key' => trim($cursorKey)]);
        $value = $stmt->fetchColumn();
        if (!is_scalar($value)) return null;
        $value = trim((string)$value);
        return $value === '' ? null : $value;
    }
}
