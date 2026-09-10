<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/amazon-returns/GmailParser.php';
require_once __DIR__ . '/../../includes/amazon-returns/SourceCursorStore.php';

final class SvAmazonGmailIngestor
{
    public const HISTORY_CURSOR_KEY = 'history_id_v2';

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

    public static function saveCursor(
        SvAmazonSourceCursorStore $target,
        string $cursorKey,
        string $cursorValue,
        array $metadata=[]
    ): void {
        $cursorKey=self::persistedCursorKey($cursorKey);
        $cursorValue=trim($cursorValue);
        if($cursorValue===''){
            throw new InvalidArgumentException('Gmail cursor key/value cannot be empty.');
        }
        $target->save('GMAIL',$cursorKey,$cursorValue,$metadata);
    }

    public static function loadCursor(
        SvAmazonSourceCursorStore $target,
        string $cursorKey
    ): ?string {
        $row=$target->load('GMAIL',self::persistedCursorKey($cursorKey));
        return is_array($row)?(string)$row['value']:null;
    }

    private static function persistedCursorKey(string $cursorKey): string
    {
        $cursorKey=trim($cursorKey);
        if($cursorKey==='')throw new InvalidArgumentException('Gmail cursor key/value cannot be empty.');
        return $cursorKey==='history_id' ? self::HISTORY_CURSOR_KEY : $cursorKey;
    }
}
