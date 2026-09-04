<?php
declare(strict_types=1);

final class SvAmazonSafeTStatusService
{
    public static function readKey(int $caseId, string $safeTId, DateTimeInterface $now): string
    {
        if ($caseId < 1 || trim($safeTId) === '') throw new InvalidArgumentException('SAFE-T read key requires case and claim.');
        $ts = DateTimeImmutable::createFromInterface($now)->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $bucket = intdiv($ts, 900) * 900;
        return hash('sha256', 'safe-t-read|' . $caseId . '|' . trim($safeTId) . '|' . gmdate('YmdHi', $bucket));
    }

    public static function observationKey(int $caseId, array $read, ?string $snapshotHash = null): string
    {
        $parts = [
            'safe-t-status',
            (string)$caseId,
            (string)($read['safe_t_id'] ?? ''),
            (string)($read['claim_status'] ?? 'UNKNOWN'),
            (string)($read['denied_at'] ?? ''),
            (string)($read['appeal_deadline_at'] ?? ''),
            (string)($read['decision_fingerprint'] ?? ''),
            !empty($read['appeal_submitted']) ? 'appeal-submitted' : 'no-appeal',
            !empty($read['appeal_denied']) ? 'appeal-denied' : 'appeal-not-denied',
        ];
        return hash('sha256', implode('|', $parts));
    }

    public static function nextState(string $currentState, string $claimStatus, bool $appealDenied = false): string
    {
        return match (strtoupper(trim($claimStatus))) {
            'DENIED' => $appealDenied ? 'APPEAL_DENIED_FINAL' : 'SAFE_T_DENIED',
            'APPROVED' => $currentState === 'APPEAL_SUBMITTED' ? 'APPEAL_APPROVED' : 'SAFE_T_APPROVED',
            'INFO_REQUESTED' => 'SAFE_T_INFO_REQUESTED',
            'PENDING' => in_array($currentState, ['POLICY_REVIEW_REQUIRED','SAFE_T_ELIGIBLE','SAFE_T_READY','AWAITING_RETURN','REFUND_DETECTED'], true)
                ? 'SAFE_T_SUBMITTED' : $currentState,
            default => $currentState,
        };
    }

    public static function repeatCount(?string $previousFingerprint, int $currentCount, ?string $newFingerprint): int
    {
        $previous = strtolower(trim((string)$previousFingerprint));
        $new = strtolower(trim((string)$newFingerprint));
        if ($previous === '' || $new === '') return max(0, $currentCount);
        return hash_equals($previous, $new) ? max(0, $currentCount) + 1 : max(0, $currentCount);
    }
}
