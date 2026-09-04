<?php

declare(strict_types=1);

final class SvAmazonDenialAnalyzer
{
    public function normalize(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');
        $replacements = [
            '/\b[0-9]{3}-[0-9]{7}-[0-9]{7}\b/u' => '<order>',
            '/\b[0-9]{4,6}-[0-9]{4,6}-[0-9]{6,8}\b/u' => '<safe-t>',
            '/\bcase\s*#?\s*[0-9]{6,}\b/ui' => 'case <id>',
            '/\b[0-9]{1,2}[\/.-][0-9]{1,2}[\/.-][0-9]{2,4}\b/u' => '<date>',
            '/\b[0-9]{4}-[0-9]{2}-[0-9]{2}\b/u' => '<date>',
            '/\b[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?\b/u' => '<time>',
            '/\b(?:r\$|brl)\s*[0-9]+(?:[\.,][0-9]{1,2})?\b/ui' => '<amount>',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }
        $text = preg_replace('/[\p{Z}\s]+/u', ' ', trim($text)) ?? trim($text);
        $text = preg_replace('/\s*([,.;:!?])\s*/u', '$1 ', $text) ?? $text;
        return trim($text);
    }

    public function fingerprint(string $text): string
    {
        return hash('sha256', $this->normalize($text));
    }

    /** @return array{repeated:bool,non_substantive:bool,fingerprint:string,normalized:string,addressed_facts:list<string>} */
    public function analyze(array $current, string $newText): array
    {
        $normalized = $this->normalize($newText);
        $fingerprint = hash('sha256', $normalized);
        $previousText = (string)($current['previous_denial_text'] ?? '');
        $previousFingerprint = trim((string)($current['previous_denial_fingerprint'] ?? ''));
        if ($previousFingerprint === '' && $previousText !== '') {
            $previousFingerprint = $this->fingerprint($previousText);
        }
        $repeated = $previousFingerprint !== '' && hash_equals($previousFingerprint, $fingerprint);

        $addressed = [];
        $facts = is_array($current['submitted_facts'] ?? null) ? $current['submitted_facts'] : [];
        foreach ($facts as $fact) {
            if (!is_scalar($fact)) continue;
            $factText = trim($this->normalize((string)$fact));
            if ($factText !== '' && str_contains($normalized, $factText)) {
                $addressed[] = (string)$fact;
            }
        }
        $nonSubstantive = $repeated && $facts !== [] && $addressed === [];

        return [
            'repeated' => $repeated,
            'non_substantive' => $nonSubstantive,
            'fingerprint' => $fingerprint,
            'normalized' => $normalized,
            'addressed_facts' => $addressed,
        ];
    }
}
