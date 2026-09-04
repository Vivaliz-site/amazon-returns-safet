<?php
declare(strict_types=1);

final class SvAmazonReturnsSchema
{
    public static function ensure(PDO $db): void
    {
        foreach (self::statements() as $statement) {
            $db->exec($statement);
        }
    }

    /** @return list<string> */
    public static function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_cases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `amazon_order_id` VARCHAR(32) NOT NULL,
    `amazon_order_item_id` VARCHAR(64) NOT NULL,
    `marketplace_id` VARCHAR(32) NOT NULL,
    `sku` VARCHAR(128) NULL,
    `asin` VARCHAR(32) NULL,
    `quantity_ordered` INT NOT NULL DEFAULT 1,
    `quantity_refunded` INT NOT NULL DEFAULT 0,
    `quantity_received` INT NOT NULL DEFAULT 0,
    `program` VARCHAR(64) NOT NULL DEFAULT 'UNKNOWN',
    `refund_initiator` VARCHAR(40) NOT NULL DEFAULT 'UNKNOWN',
    `refund_at` DATETIME NULL,
    `seller_debit_at` DATETIME NULL,
    `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `expected_reimbursement_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `reconciled_credit_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `physical_status` VARCHAR(48) NOT NULL DEFAULT 'NOT_RECEIVED',
    `state` VARCHAR(64) NOT NULL,
    `policy_version_id` BIGINT UNSIGNED NULL,
    `eligibility_at` DATETIME NULL,
    `next_action_at` DATETIME NULL,
    `safe_t_id` VARCHAR(64) NULL,
    `support_case_id` VARCHAR(64) NULL,
    `repeated_denial_count` INT NOT NULL DEFAULT 0,
    `last_denial_fingerprint` CHAR(64) NULL,
    `appeal_deadline_at` DATETIME NULL,
    `terminal_reason` VARCHAR(128) NULL,
    `closed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_case_order_item` (`amazon_order_id`, `amazon_order_item_id`),
    KEY `idx_amazon_return_cases_state_action` (`state`, `next_action_at`),
    KEY `idx_amazon_return_cases_safe_t` (`safe_t_id`),
    KEY `idx_amazon_return_cases_support_case` (`support_case_id`),
    KEY `idx_amazon_return_cases_eligibility` (`eligibility_at`),
    KEY `idx_amazon_return_cases_seller_debit` (`seller_debit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `source` VARCHAR(32) NOT NULL,
    `source_event_id` VARCHAR(191) NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `occurred_at` DATETIME NOT NULL,
    `payload_json` JSON NOT NULL,
    `evidence_sha256` CHAR(64) NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_events_idempotency` (`idempotency_key`),
    KEY `idx_amazon_return_events_case_time` (`case_id`, `occurred_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_policies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `policy_key` VARCHAR(96) NOT NULL,
    `marketplace_id` VARCHAR(32) NOT NULL,
    `program` VARCHAR(64) NOT NULL,
    `effective_from` DATE NOT NULL,
    `effective_to` DATE NULL,
    `eligibility_days` INT NOT NULL,
    `basis` VARCHAR(32) NOT NULL DEFAULT 'SELLER_DEBIT_AT',
    `source_url` TEXT NOT NULL,
    `source_hash` CHAR(64) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_policy_version` (`policy_key`, `marketplace_id`, `program`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_evidence` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `kind` VARCHAR(64) NOT NULL,
    `source` VARCHAR(32) NOT NULL,
    `external_id` VARCHAR(191) NULL,
    `content_sha256` CHAR(64) NOT NULL,
    `storage_ref` VARCHAR(512) NULL,
    `metadata_json` JSON NOT NULL,
    `captured_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_evidence_content` (`case_id`, `kind`, `content_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_outbox` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `kind` VARCHAR(64) NOT NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `payload_json` JSON NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'PENDING',
    `attempt_count` INT NOT NULL DEFAULT 0,
    `available_at` DATETIME NOT NULL,
    `locked_at` DATETIME NULL,
    `last_error` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_outbox_idempotency` (`idempotency_key`),
    KEY `idx_amazon_return_outbox_available` (`status`, `available_at`),
    KEY `idx_amazon_return_outbox_case_kind` (`case_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_dead_letters` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `outbox_id` BIGINT UNSIGNED NOT NULL,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `kind` VARCHAR(64) NOT NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `payload_sha256` CHAR(64) NOT NULL,
    `payload_json` JSON NOT NULL,
    `error_class` VARCHAR(191) NOT NULL,
    `error_message` TEXT NOT NULL,
    `attempt_count` INT NOT NULL,
    `first_attempt_at` DATETIME NULL,
    `failed_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_dead_letter_outbox` (`outbox_id`),
    KEY `idx_amazon_return_dead_letters_case_kind` (`case_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_source_cursors` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source` VARCHAR(32) NOT NULL,
    `cursor_key` VARCHAR(96) NOT NULL,
    `cursor_value` VARCHAR(512) NOT NULL,
    `metadata_json` JSON NULL,
    `observed_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_source_cursor` (`source`, `cursor_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_overrides` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `actor_id` BIGINT UNSIGNED NOT NULL,
    `reason` TEXT NOT NULL,
    `before_json` JSON NOT NULL,
    `after_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_amazon_return_overrides_case_time` (`case_id`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}
