<?php
declare(strict_types=1);

require_once __DIR__ . '/Enums.php';
require_once __DIR__ . '/EventStore.php';

final class SvAmazonReturnProjector
{
    /** @return array<string,mixed> */
    public static function project(PDO $db, int $caseId): array
    {
        if ($caseId < 1) {
            throw new InvalidArgumentException('Amazon return case ID must be positive.');
        }

        $case = self::loadCase($db, $caseId);
        $facts = self::initialFacts($case);
        foreach (SvAmazonReturnEventStore::eventsForCase($db, $caseId) as $event) {
            self::applyEvent($facts, $event);
        }
        self::finalize($facts);
        self::writeProjection($db, $caseId, $facts);

        return array_replace($case, $facts);
    }

    /** @return array<string,mixed> */
    private static function loadCase(PDO $db, int $caseId): array
    {
        $statement = $db->prepare('SELECT * FROM `amazon_return_cases` WHERE `id` = :case_id LIMIT 1');
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare Amazon return case projection query.');
        }
        $statement->execute([':case_id' => $caseId]);
        $case = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($case)) {
            throw new OutOfBoundsException("Amazon return case {$caseId} was not found.");
        }
        return $case;
    }

    /** @return array<string,mixed> */
    private static function initialFacts(array $case): array
    {
        return [
            'quantity_ordered' => max(0, (int) ($case['quantity_ordered'] ?? 0)),
            'quantity_refunded' => 0,
            'quantity_received' => 0,
            'program' => SvAmazonReturnPrograms::UNKNOWN,
            'refund_initiator' => SvAmazonRefundInitiators::UNKNOWN,
            'refund_at' => null,
            'seller_debit_at' => null,
            'refund_amount' => '0.00',
            'physical_status' => in_array((string)($case['physical_status'] ?? ''), SvAmazonReturnPhysicalStatuses::all(), true)
                ? (string)$case['physical_status'] : SvAmazonReturnPhysicalStatuses::NOT_RECEIVED,
            'state' => SvAmazonReturnStates::isValid((string)($case['state'] ?? ''))
                ? (string)$case['state'] : SvAmazonReturnStates::REFUND_DETECTED,
            'terminal_reason' => isset($case['terminal_reason']) ? (string)$case['terminal_reason'] : null,
            'closed_at' => isset($case['closed_at']) && $case['closed_at'] !== null ? (string)$case['closed_at'] : null,
            'order_at' => null,
            'exposed_quantity' => 0,
            'has_physical_discrepancy' => false,
        ];
    }

    /** @param array<string,mixed> $facts @param array<string,mixed> $event */
    private static function applyEvent(array &$facts, array $event): void
    {
        $type = (string) ($event['event_type'] ?? '');
        $occurredAt = self::utcString($event['occurred_at'] ?? null);
        $payload = $event['payload'] ?? [];
        if (!is_array($payload)) {
            throw new UnexpectedValueException('Amazon return event payload must decode to an array.');
        }

        self::copyText($facts, $payload, 'program', SvAmazonReturnPrograms::all());
        self::copyText($facts, $payload, 'refund_initiator', SvAmazonRefundInitiators::all());
        if (array_key_exists('order_at', $payload)) {
            $facts['order_at'] = self::utcString($payload['order_at']);
        }
        if (array_key_exists('quantity_ordered', $payload)) {
            $facts['quantity_ordered'] = self::nonNegativeInt($payload['quantity_ordered'], 'quantity_ordered');
        }

        switch ($type) {
            case 'ORDER_SYNCED':
            case 'CASE_CREATED':
                break;

            case 'REFUND_DETECTED':
            case 'REFUND_CONFIRMED':
                $facts['refund_at'] = array_key_exists('refund_at', $payload)
                    ? self::utcString($payload['refund_at'])
                    : $occurredAt;
                if (array_key_exists('seller_debit_at', $payload)) {
                    $facts['seller_debit_at'] = self::utcString($payload['seller_debit_at']);
                }
                if (array_key_exists('quantity_refunded', $payload)) {
                    $facts['quantity_refunded'] = self::nonNegativeInt($payload['quantity_refunded'], 'quantity_refunded');
                }
                if (array_key_exists('refund_amount', $payload)) {
                    $facts['refund_amount'] = self::decimal($payload['refund_amount']);
                }
                break;

            case 'SELLER_DEBIT_CONFIRMED':
                $facts['seller_debit_at'] = array_key_exists('seller_debit_at', $payload)
                    ? self::utcString($payload['seller_debit_at'])
                    : $occurredAt;
                break;

            case 'RETURN_IN_TRANSIT':
                $facts['physical_status'] = SvAmazonReturnPhysicalStatuses::IN_TRANSIT;
                break;

            case 'CARRIER_DELIVERED':
                $facts['physical_status'] = SvAmazonReturnPhysicalStatuses::CARRIER_DELIVERED_PENDING_PHYSICAL;
                break;

            case 'PHYSICAL_RECEIVED':
            case 'WAREHOUSE_RECEIVED':
            case 'RECEIVED_OK':
                $condition = strtoupper(trim((string)($payload['condition'] ?? 'OK')));
                if (!in_array($condition, ['OK', 'INTACT'], true)) {
                    $facts['has_physical_discrepancy'] = true;
                }
                if (array_key_exists('quantity_received_total', $payload)) {
                    $facts['quantity_received'] = self::nonNegativeInt(
                        $payload['quantity_received_total'],
                        'quantity_received_total'
                    );
                } elseif ($type === 'RECEIVED_OK' && !array_key_exists('quantity', $payload)) {
                    $facts['quantity_received'] = max(
                        (int) $facts['quantity_received'],
                        (int) $facts['quantity_refunded']
                    );
                } else {
                    $facts['quantity_received'] += self::nonNegativeInt($payload['quantity'] ?? 1, 'quantity');
                }
                $facts['closed_at'] = $occurredAt;
                break;
        }
    }

    /** @param array<string,mixed> $facts */
    private static function finalize(array &$facts): void
    {
        $ordered = max(0, (int) $facts['quantity_ordered']);
        $refunded = min($ordered, max(0, (int) $facts['quantity_refunded']));
        $received = min($refunded, max(0, (int) $facts['quantity_received']));
        $facts['quantity_refunded'] = $refunded;
        $facts['quantity_received'] = $received;
        $facts['exposed_quantity'] = max(0, $refunded - $received);

        if (($facts['has_physical_discrepancy'] ?? false) === true) {
            $facts['physical_status'] = SvAmazonReturnPhysicalStatuses::RECEIVED_DISCREPANT;
            $facts['state'] = SvAmazonReturnStates::RECEIVED_DISCREPANT;
            $facts['terminal_reason'] = null;
            $facts['closed_at'] = null;
            return;
        }
        if ($refunded > 0 && $facts['exposed_quantity'] === 0) {
            $facts['physical_status'] = SvAmazonReturnPhysicalStatuses::RECEIVED_OK;
            $facts['state'] = SvAmazonReturnStates::RECEIVED_OK;
            $facts['terminal_reason'] = 'PHYSICAL_RETURN_RECEIVED';
            return;
        }
        $preserveState = self::preserveOperationalState((string)$facts['state']);
        if ($received > 0) {
            $facts['physical_status'] = SvAmazonReturnPhysicalStatuses::RECEIVED_DISCREPANT;
            if (!$preserveState) {
                $facts['state'] = SvAmazonReturnStates::RECEIVED_DISCREPANT;
                $facts['closed_at'] = null;
            }
            return;
        }
        if ($preserveState) return;
        $facts['closed_at'] = null;
        $facts['state'] = match ($facts['physical_status']) {
            SvAmazonReturnPhysicalStatuses::IN_TRANSIT => SvAmazonReturnStates::IN_TRANSIT,
            SvAmazonReturnPhysicalStatuses::CARRIER_DELIVERED_PENDING_PHYSICAL =>
                SvAmazonReturnStates::CARRIER_DELIVERED_PENDING_PHYSICAL,
            default => SvAmazonReturnStates::AWAITING_RETURN,
        };
    }

    private static function preserveOperationalState(string $state): bool
    {
        return in_array($state, [
            SvAmazonReturnStates::POLICY_REVIEW_REQUIRED,
            SvAmazonReturnStates::SAFE_T_ELIGIBLE,
            SvAmazonReturnStates::SAFE_T_READY,
            SvAmazonReturnStates::SAFE_T_SUBMITTED,
            SvAmazonReturnStates::SAFE_T_APPROVED,
            SvAmazonReturnStates::SAFE_T_DENIED,
            SvAmazonReturnStates::SAFE_T_INFO_REQUESTED,
            SvAmazonReturnStates::APPEAL_REQUIRED,
            SvAmazonReturnStates::APPEAL_SUBMITTED,
            SvAmazonReturnStates::APPEAL_APPROVED,
            SvAmazonReturnStates::APPEAL_DENIED_FINAL,
            SvAmazonReturnStates::EMAIL_REVIEW_SENT,
            SvAmazonReturnStates::CREDIT_PENDING,
            SvAmazonReturnStates::RECOVERED,
            SvAmazonReturnStates::SUPPORT_ESCALATION,
            SvAmazonReturnStates::CLOSED_LOSS,
        ], true);
    }

    /** @param array<string,mixed> $facts */
    private static function writeProjection(PDO $db, int $caseId, array $facts): void
    {
        $statement = $db->prepare(
            'UPDATE `amazon_return_cases` SET '
            . '`quantity_ordered` = :quantity_ordered, `quantity_refunded` = :quantity_refunded, '
            . '`quantity_received` = :quantity_received, `program` = :program, '
            . '`refund_initiator` = :refund_initiator, `refund_at` = :refund_at, '
            . '`seller_debit_at` = :seller_debit_at, `refund_amount` = :refund_amount, '
            . '`physical_status` = :physical_status, `state` = :state, '
            . '`terminal_reason` = :terminal_reason, `closed_at` = :closed_at '
            . 'WHERE `id` = :case_id'
        );
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare Amazon return case projection update.');
        }
        $statement->execute([
            ':quantity_ordered' => $facts['quantity_ordered'],
            ':quantity_refunded' => $facts['quantity_refunded'],
            ':quantity_received' => $facts['quantity_received'],
            ':program' => $facts['program'],
            ':refund_initiator' => $facts['refund_initiator'],
            ':refund_at' => $facts['refund_at'],
            ':seller_debit_at' => $facts['seller_debit_at'],
            ':refund_amount' => $facts['refund_amount'],
            ':physical_status' => $facts['physical_status'],
            ':state' => $facts['state'],
            ':terminal_reason' => $facts['terminal_reason'],
            ':closed_at' => $facts['closed_at'],
            ':case_id' => $caseId,
        ]);
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source @param list<string> $allowed */
    private static function copyText(array &$target, array $source, string $key, array $allowed): void
    {
        if (!array_key_exists($key, $source)) {
            return;
        }
        $value = $source[$key];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new UnexpectedValueException("Invalid {$key} in Amazon return event.");
        }
        $target[$key] = $value;
    }

    private static function nonNegativeInt(mixed $value, string $name): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer < 0) {
            throw new UnexpectedValueException("Amazon return {$name} must be a non-negative integer.");
        }
        return $integer;
    }

    private static function decimal(mixed $value): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || !is_numeric($value) || (float) $value < 0) {
            throw new UnexpectedValueException('Amazon return refund_amount must be a non-negative decimal.');
        }
        return number_format((float) $value, 2, '.', '');
    }

    private static function utcString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }
        if (!is_string($value)) {
            throw new UnexpectedValueException('Amazon return timestamps must be UTC date-time strings.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            throw new UnexpectedValueException('Amazon return timestamps must use Y-m-d H:i:s UTC format.');
        }
        return $value;
    }
}
