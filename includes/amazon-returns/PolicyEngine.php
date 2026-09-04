<?php
declare(strict_types=1);

require_once __DIR__ . '/Enums.php';

final class SvAmazonReturnPolicyEngine
{
    private const EXCEPTION_EFFECTIVE_FROM = '2026-04-21';

    /** @return array{eligible:bool,eligibility_at:?string,policy_version_id:int|string|null,reason:string,state:string,exposed_quantity:int,can_auto_write:bool,auto_write_allowed:bool} */
    public static function evaluate(array $case, DateTimeImmutable $now): array
    {
        $nowUtc = $now->setTimezone(new DateTimeZone('UTC'));
        $ordered = self::quantity($case, 'quantity_ordered');
        $refunded = min($ordered, self::quantity($case, 'quantity_refunded'));
        $received = min($refunded, self::quantity($case, 'quantity_received'));
        $exposed = max(0, $refunded - $received);
        $physicalStatus = (string) ($case['physical_status'] ?? SvAmazonReturnPhysicalStatuses::NOT_RECEIVED);

        if ($physicalStatus === SvAmazonReturnPhysicalStatuses::RECEIVED_OK || ($refunded > 0 && $exposed === 0)) {
            return self::decision(false, null, null, 'PHYSICAL_RETURN_RECEIVED', SvAmazonReturnStates::RECEIVED_OK, 0, false);
        }

        $initiator = (string) ($case['refund_initiator'] ?? SvAmazonRefundInitiators::UNKNOWN);
        if ($initiator === SvAmazonRefundInitiators::UNKNOWN || !SvAmazonRefundInitiators::isValid($initiator)) {
            return self::decision(
                false,
                null,
                null,
                'REFUND_INITIATOR_UNRESOLVED',
                SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
                $exposed,
                false
            );
        }

        if ($refunded < 1 || $exposed < 1) {
            return self::decision(
                false,
                null,
                null,
                'NO_UNRESOLVED_REFUNDED_QUANTITY',
                SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
                $exposed,
                false
            );
        }

        $policy = self::selectPolicy($case);
        if ($policy === null) {
            return self::decision(
                false,
                null,
                null,
                'POLICY_UNRESOLVED',
                SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
                $exposed,
                false
            );
        }

        $basisAt = self::basisDate($case, (string) ($policy['basis'] ?? 'SELLER_DEBIT_AT'));
        $days = filter_var($policy['eligibility_days'] ?? null, FILTER_VALIDATE_INT);
        $policyId = $policy['id'] ?? $policy['policy_version_id'] ?? null;
        if (!$basisAt instanceof DateTimeImmutable || $days === false || $days < 1 || (!is_int($policyId) && !is_string($policyId))) {
            return self::decision(
                false,
                null,
                null,
                'POLICY_INPUT_UNRESOLVED',
                SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
                $exposed,
                false
            );
        }

        $eligibilityAt = $basisAt->add(new DateInterval('P' . $days . 'D'));
        $eligible = $nowUtc >= $eligibilityAt;
        $state = $eligible
            ? SvAmazonReturnStates::SAFE_T_ELIGIBLE
            : self::waitingState($physicalStatus);

        return self::decision(
            $eligible,
            $eligibilityAt->format('Y-m-d H:i:s'),
            $policyId,
            $eligible ? 'ELIGIBILITY_REACHED' : 'ELIGIBILITY_PENDING',
            $state,
            $exposed,
            $eligible
        );
    }

    /** @return array<string,mixed>|null */
    private static function selectPolicy(array $case): ?array
    {
        $policies = $case['policies'] ?? $case['policy_versions'] ?? null;
        if (!is_array($policies)) {
            return null;
        }

        $marketplaceId = (string) ($case['marketplace_id'] ?? '');
        $program = (string) ($case['program'] ?? SvAmazonReturnPrograms::UNKNOWN);
        $selectionDate = self::selectionDate($case, $program);
        if ($marketplaceId === '' || !$selectionDate instanceof DateTimeImmutable) {
            return null;
        }

        $wantedProgram = $program;
        if (in_array($program, [SvAmazonReturnPrograms::FBA_ONSITE, SvAmazonReturnPrograms::DELIVERY_BY_AMAZON], true)
            && $selectionDate->format('Y-m-d') < self::EXCEPTION_EFFECTIVE_FROM) {
            $wantedProgram = SvAmazonReturnPrograms::STANDARD;
        }

        $candidates = [];
        foreach ($policies as $policy) {
            if (!is_array($policy)
                || (string) ($policy['status'] ?? 'ACTIVE') !== 'ACTIVE'
                || (string) ($policy['marketplace_id'] ?? '') !== $marketplaceId
                || (string) ($policy['program'] ?? '') !== $wantedProgram) {
                continue;
            }

            $effectiveFrom = self::dateOnly($policy['effective_from'] ?? null);
            $effectiveTo = self::dateOnly($policy['effective_to'] ?? null, true);
            $selectionDay = $selectionDate->format('Y-m-d');
            if (!$effectiveFrom instanceof DateTimeImmutable
                || $selectionDay < $effectiveFrom->format('Y-m-d')
                || ($effectiveTo instanceof DateTimeImmutable && $selectionDay > $effectiveTo->format('Y-m-d'))) {
                continue;
            }
            $policy['_effective_from_utc'] = $effectiveFrom;
            $candidates[] = $policy;
        }

        usort($candidates, static function (array $left, array $right): int {
            $dateOrder = $right['_effective_from_utc'] <=> $left['_effective_from_utc'];
            if ($dateOrder !== 0) {
                return $dateOrder;
            }
            return strcmp((string) ($right['id'] ?? ''), (string) ($left['id'] ?? ''));
        });

        if ($candidates === []) {
            return null;
        }
        unset($candidates[0]['_effective_from_utc']);
        return $candidates[0];
    }

    private static function selectionDate(array $case, string $program): ?DateTimeImmutable
    {
        $orderAt = self::utcDateTime($case['order_at'] ?? null);
        if (in_array($program, [SvAmazonReturnPrograms::FBA_ONSITE, SvAmazonReturnPrograms::DELIVERY_BY_AMAZON], true)) {
            return $orderAt;
        }
        return $orderAt
            ?? self::utcDateTime($case['seller_debit_at'] ?? null)
            ?? self::utcDateTime($case['refund_at'] ?? null);
    }

    private static function basisDate(array $case, string $basis): ?DateTimeImmutable
    {
        return match ($basis) {
            'REFUND_AT' => self::utcDateTime($case['refund_at'] ?? null),
            'SELLER_DEBIT_OR_REFUND_AT' => self::utcDateTime($case['seller_debit_at'] ?? null)
                ?? self::utcDateTime($case['refund_at'] ?? null),
            'SELLER_DEBIT_AT' => self::utcDateTime($case['seller_debit_at'] ?? null)
                ?? self::utcDateTime($case['refund_at'] ?? null),
            default => null,
        };
    }

    private static function waitingState(string $physicalStatus): string
    {
        return match ($physicalStatus) {
            SvAmazonReturnPhysicalStatuses::IN_TRANSIT => SvAmazonReturnStates::IN_TRANSIT,
            SvAmazonReturnPhysicalStatuses::CARRIER_DELIVERED_PENDING_PHYSICAL =>
                SvAmazonReturnStates::CARRIER_DELIVERED_PENDING_PHYSICAL,
            SvAmazonReturnPhysicalStatuses::RECEIVED_DISCREPANT => SvAmazonReturnStates::RECEIVED_DISCREPANT,
            default => SvAmazonReturnStates::AWAITING_RETURN,
        };
    }

    private static function quantity(array $case, string $key): int
    {
        $value = filter_var($case[$key] ?? 0, FILTER_VALIDATE_INT);
        return $value === false ? 0 : max(0, $value);
    }

    private static function utcDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'));
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }

    private static function dateOnly(mixed $value, bool $nullable = false): ?DateTimeImmutable
    {
        if ($nullable && ($value === null || $value === '')) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** @return array{eligible:bool,eligibility_at:?string,policy_version_id:int|string|null,reason:string,state:string,exposed_quantity:int,can_auto_write:bool,auto_write_allowed:bool} */
    private static function decision(
        bool $eligible,
        ?string $eligibilityAt,
        int|string|null $policyVersionId,
        string $reason,
        string $state,
        int $exposedQuantity,
        bool $canAutoWrite
    ): array {
        return [
            'eligible' => $eligible,
            'eligibility_at' => $eligibilityAt,
            'policy_version_id' => $policyVersionId,
            'reason' => $reason,
            'state' => $state,
            'exposed_quantity' => $exposedQuantity,
            'can_auto_write' => $canAutoWrite,
            'auto_write_allowed' => $canAutoWrite,
        ];
    }
}
