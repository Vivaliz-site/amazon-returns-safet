<?php
declare(strict_types=1);

final class SvAmazonReturnStates
{
    public const REFUND_DETECTED = 'REFUND_DETECTED';
    public const AWAITING_RETURN = 'AWAITING_RETURN';
    public const IN_TRANSIT = 'IN_TRANSIT';
    public const CARRIER_DELIVERED_PENDING_PHYSICAL = 'CARRIER_DELIVERED_PENDING_PHYSICAL';
    public const RECEIVED_OK = 'RECEIVED_OK';
    public const RECEIVED_DISCREPANT = 'RECEIVED_DISCREPANT';
    public const SAFE_T_ELIGIBLE = 'SAFE_T_ELIGIBLE';
    public const SAFE_T_READY = 'SAFE_T_READY';
    public const SAFE_T_SUBMITTED = 'SAFE_T_SUBMITTED';
    public const SAFE_T_APPROVED = 'SAFE_T_APPROVED';
    public const SAFE_T_DENIED = 'SAFE_T_DENIED';
    public const SAFE_T_INFO_REQUESTED = 'SAFE_T_INFO_REQUESTED';
    public const APPEAL_REQUIRED = 'APPEAL_REQUIRED';
    public const APPEAL_SUBMITTED = 'APPEAL_SUBMITTED';
    public const APPEAL_APPROVED = 'APPEAL_APPROVED';
    public const APPEAL_DENIED_FINAL = 'APPEAL_DENIED_FINAL';
    public const EMAIL_REVIEW_SENT = 'EMAIL_REVIEW_SENT';
    public const CREDIT_PENDING = 'CREDIT_PENDING';
    public const RECOVERED = 'RECOVERED';
    public const SUPPORT_ESCALATION = 'SUPPORT_ESCALATION';
    public const CLOSED_LOSS = 'CLOSED_LOSS';
    public const POLICY_REVIEW_REQUIRED = 'POLICY_REVIEW_REQUIRED';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::REFUND_DETECTED,
            self::AWAITING_RETURN,
            self::IN_TRANSIT,
            self::CARRIER_DELIVERED_PENDING_PHYSICAL,
            self::RECEIVED_OK,
            self::RECEIVED_DISCREPANT,
            self::SAFE_T_ELIGIBLE,
            self::SAFE_T_READY,
            self::SAFE_T_SUBMITTED,
            self::SAFE_T_APPROVED,
            self::SAFE_T_DENIED,
            self::SAFE_T_INFO_REQUESTED,
            self::APPEAL_REQUIRED,
            self::APPEAL_SUBMITTED,
            self::APPEAL_APPROVED,
            self::APPEAL_DENIED_FINAL,
            self::EMAIL_REVIEW_SENT,
            self::CREDIT_PENDING,
            self::RECOVERED,
            self::SUPPORT_ESCALATION,
            self::CLOSED_LOSS,
            self::POLICY_REVIEW_REQUIRED,
        ];
    }

    /** @return list<string> */
    public static function terminal(): array
    {
        return [self::RECEIVED_OK, self::RECOVERED, self::CLOSED_LOSS];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::terminal(), true);
    }

    public static function assertValid(string $state): void
    {
        if (!self::isValid($state)) {
            throw new InvalidArgumentException("Unknown Amazon return state: {$state}");
        }
    }
}

final class SvAmazonRefundInitiators
{
    public const AMAZON_AUTOMATIC = 'AMAZON_AUTOMATIC';
    public const AMAZON_CUSTOMER_SERVICE = 'AMAZON_CUSTOMER_SERVICE';
    public const SELLER = 'SELLER';
    public const A_TO_Z = 'A_TO_Z';
    public const UNKNOWN = 'UNKNOWN';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::AMAZON_AUTOMATIC,
            self::AMAZON_CUSTOMER_SERVICE,
            self::SELLER,
            self::A_TO_Z,
            self::UNKNOWN,
        ];
    }

    public static function isValid(string $initiator): bool
    {
        return in_array($initiator, self::all(), true);
    }
}

final class SvAmazonReturnPhysicalStatuses
{
    public const NOT_RECEIVED = 'NOT_RECEIVED';
    public const IN_TRANSIT = 'IN_TRANSIT';
    public const CARRIER_DELIVERED_PENDING_PHYSICAL = 'CARRIER_DELIVERED_PENDING_PHYSICAL';
    public const RECEIVED_OK = 'RECEIVED_OK';
    public const RECEIVED_DISCREPANT = 'RECEIVED_DISCREPANT';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::NOT_RECEIVED,
            self::IN_TRANSIT,
            self::CARRIER_DELIVERED_PENDING_PHYSICAL,
            self::RECEIVED_OK,
            self::RECEIVED_DISCREPANT,
        ];
    }
}

final class SvAmazonReturnPrograms
{
    public const UNKNOWN = 'UNKNOWN';
    public const FBA = 'FBA';
    public const STANDARD = 'STANDARD';
    public const FBA_ONSITE = 'FBA_ONSITE';
    public const DELIVERY_BY_AMAZON = 'DELIVERY_BY_AMAZON';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::UNKNOWN, self::FBA, self::STANDARD, self::FBA_ONSITE, self::DELIVERY_BY_AMAZON];
    }
}
