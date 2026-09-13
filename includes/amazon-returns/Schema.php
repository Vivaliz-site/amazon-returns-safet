<?php
declare(strict_types=1);

final class SvAmazonReturnsSchema
{
    public static function ensure(PDO $db): void
    {
        foreach (self::statements() as $statement) $db->exec($statement);
    }

    /** @return list<string> */
    public static function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_reviews` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'OPEN',
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `reason` VARCHAR(96) NOT NULL,
    `context_hash` CHAR(64) NOT NULL,
    `context_json` JSON NOT NULL,
    `open_key` CHAR(64) NULL,
    `evidence_refs_json` JSON NULL,
    `affected_case_preview_json` JSON NULL,
    `ai_provider` VARCHAR(32) NULL,
    `ai_suggestion_json` JSON NULL,
    `ai_model` VARCHAR(191) NULL,
    `ai_suggested_at` DATETIME NULL,
    `ai_error_count` INT NOT NULL DEFAULT 0,
    `ai_error_class` VARCHAR(191) NULL,
    `ai_error_at` DATETIME NULL,
    `human_decision_json` JSON NULL,
    `decision_mode` VARCHAR(24) NULL,
    `actor` VARCHAR(191) NULL,
    `source_version` VARCHAR(191) NULL,
    `decided_at` DATETIME NULL,
    `resulting_rule_id` BIGINT UNSIGNED NULL,
    `outcome_json` JSON NULL,
    `outcome_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_review_open_key` (`tenant_id`, `amazon_connection_id`, `open_key`),
    KEY `idx_review_queue` (`tenant_id`, `amazon_connection_id`, `status`, `reason`, `created_at`, `id`),
    KEY `idx_review_case` (`tenant_id`, `amazon_connection_id`, `case_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_learned_rules` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `rule_family_key` VARCHAR(191) NOT NULL,
    `version` INT UNSIGNED NOT NULL,
    `status` VARCHAR(24) NOT NULL,
    `match_json` JSON NOT NULL,
    `effect_json` JSON NOT NULL,
    `source_review_id` BIGINT UNSIGNED NOT NULL,
    `specificity` INT UNSIGNED NOT NULL DEFAULT 0,
    `outcome_counters_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `activated_at` DATETIME NULL,
    `superseded_at` DATETIME NULL,
    `disabled_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_learned_rule_version` (`tenant_id`, `amazon_connection_id`, `rule_family_key`, `version`),
    KEY `idx_learned_rule_active` (`tenant_id`, `amazon_connection_id`, `status`, `specificity`, `id`),
    KEY `idx_learned_rule_source` (`tenant_id`, `amazon_connection_id`, `source_review_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_rule_applications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `application_key` CHAR(64) NOT NULL,
    `rule_id` BIGINT UNSIGNED NOT NULL,
    `rule_version` INT UNSIGNED NOT NULL,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `signature_hash` CHAR(64) NOT NULL,
    `effect_hash` CHAR(64) NOT NULL,
    `result` VARCHAR(64) NOT NULL,
    `blockers_json` JSON NOT NULL,
    `action_ref` VARCHAR(191) NULL,
    `outcome` VARCHAR(32) NOT NULL DEFAULT 'PENDING',
    `outcome_evidence_refs_json` JSON NULL,
    `counted_outcomes_json` JSON NULL,
    `outcome_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rule_application_key` (`tenant_id`, `amazon_connection_id`, `application_key`),
    KEY `idx_rule_application_case` (`tenant_id`, `amazon_connection_id`, `case_id`, `id`),
    KEY `idx_rule_application_outcome` (`tenant_id`, `amazon_connection_id`, `outcome`, `id`),
    KEY `idx_rule_application_rule` (`tenant_id`, `amazon_connection_id`, `rule_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_tenants` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(96) NOT NULL,
    `name` VARCHAR(191) NOT NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_tenants_slug` (`slug`),
    KEY `idx_amazon_return_tenants_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_tenant_users` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `identity_provider` VARCHAR(32) NOT NULL,
    `subject` VARCHAR(191) NOT NULL,
    `email` VARCHAR(254) NULL,
    `display_name` VARCHAR(191) NULL,
    `role` VARCHAR(24) NOT NULL DEFAULT 'VIEWER',
    `status` VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_tenant_user_subject` (`tenant_id`, `identity_provider`, `subject`),
    KEY `idx_amazon_return_tenant_users_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_connections` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `connection_key` VARCHAR(96) NOT NULL,
    `label` VARCHAR(191) NOT NULL,
    `selling_partner_id` VARCHAR(64) NULL,
    `region` VARCHAR(16) NOT NULL,
    `endpoint` VARCHAR(255) NOT NULL,
    `marketplace_id` VARCHAR(32) NOT NULL,
    `credential_ref` VARCHAR(255) NULL,
    `status` VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',
    `authorized_at` DATETIME NULL,
    `authorization_expires_at` DATETIME NULL,
    `last_verified_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_connection_key` (`tenant_id`, `connection_key`),
    UNIQUE KEY `uq_amazon_return_selling_partner` (`region`, `selling_partner_id`),
    KEY `idx_amazon_return_connections_status` (`tenant_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_feature_flags` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `flag_key` VARCHAR(96) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `config_json` JSON NULL,
    `updated_by_user_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_feature_flag` (`tenant_id`, `amazon_connection_id`, `flag_key`),
    KEY `idx_amazon_return_feature_flags_enabled` (`tenant_id`, `amazon_connection_id`, `enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_cases` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_amazon_return_case_order_item` (`tenant_id`, `amazon_connection_id`, `amazon_order_id`, `amazon_order_item_id`),
    KEY `idx_amazon_return_cases_state_action` (`tenant_id`, `amazon_connection_id`, `state`, `next_action_at`),
    KEY `idx_amazon_return_cases_safe_t` (`tenant_id`, `amazon_connection_id`, `safe_t_id`),
    KEY `idx_amazon_return_cases_support_case` (`tenant_id`, `amazon_connection_id`, `support_case_id`),
    KEY `idx_amazon_return_cases_eligibility` (`tenant_id`, `amazon_connection_id`, `eligibility_at`),
    KEY `idx_amazon_return_cases_seller_debit` (`tenant_id`, `amazon_connection_id`, `seller_debit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_amazon_return_events_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`),
    KEY `idx_amazon_return_events_case_time` (`tenant_id`, `amazon_connection_id`, `case_id`, `occurred_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_policies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_amazon_return_policy_version` (`tenant_id`, `policy_key`, `marketplace_id`, `program`, `effective_from`),
    KEY `idx_amazon_return_policies_active` (`tenant_id`, `marketplace_id`, `program`, `status`, `effective_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_evidence` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_amazon_return_evidence_content` (`tenant_id`, `amazon_connection_id`, `case_id`, `kind`, `content_sha256`),
    KEY `idx_amazon_return_evidence_case` (`tenant_id`, `amazon_connection_id`, `case_id`, `captured_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_outbox` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_amazon_return_outbox_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`),
    KEY `idx_amazon_return_outbox_available` (`tenant_id`, `amazon_connection_id`, `status`, `available_at`),
    KEY `idx_amazon_return_outbox_case_kind` (`tenant_id`, `amazon_connection_id`, `case_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_dead_letters` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uq_amazon_return_dead_letter_outbox` (`tenant_id`, `amazon_connection_id`, `outbox_id`),
    KEY `idx_amazon_return_dead_letters_case_kind` (`tenant_id`, `amazon_connection_id`, `case_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_source_cursors` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `source` VARCHAR(32) NOT NULL,
    `cursor_key` VARCHAR(96) NOT NULL,
    `cursor_value` VARCHAR(512) NOT NULL,
    `metadata_json` JSON NULL,
    `observed_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_source_cursor` (`tenant_id`, `amazon_connection_id`, `source`, `cursor_key`),
    KEY `idx_amazon_return_source_cursors_observed` (`tenant_id`, `amazon_connection_id`, `source`, `observed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_overrides` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `case_id` BIGINT UNSIGNED NOT NULL,
    `actor_id` BIGINT UNSIGNED NOT NULL,
    `reason` TEXT NOT NULL,
    `before_json` JSON NOT NULL,
    `after_json` JSON NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_amazon_return_overrides_case_time` (`tenant_id`, `amazon_connection_id`, `case_id`, `created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS `amazon_return_erp_sales_returns` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `amazon_order_id` VARCHAR(32) NOT NULL,
    `original_invoice_id` VARCHAR(64) NULL,
    `original_invoice_number` VARCHAR(64) NULL,
    `original_invoice_key` VARCHAR(64) NULL,
    `erp_sales_return_id` VARCHAR(64) NULL,
    `status` VARCHAR(48) NOT NULL DEFAULT 'PENDING',
    `return_invoice_id` VARCHAR(64) NULL,
    `return_invoice_number` VARCHAR(64) NULL,
    `return_invoice_key` VARCHAR(64) NULL,
    `return_invoice_status` VARCHAR(64) NULL,
    `return_invoice_issued_at` DATETIME NULL,
    `idempotency_key` CHAR(64) NOT NULL,
    `last_checked_at` DATETIME NULL,
    `created_in_erp_at` DATETIME NULL,
    `last_error_code` VARCHAR(96) NULL,
    `last_error_message` VARCHAR(512) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_amazon_return_erp_sales_return_order` (`tenant_id`, `amazon_connection_id`, `amazon_order_id`),
    UNIQUE KEY `uq_amazon_return_erp_sales_return_idempotency` (`tenant_id`, `amazon_connection_id`, `idempotency_key`),
    KEY `idx_amazon_return_erp_sales_return_status` (`tenant_id`, `amazon_connection_id`, `status`, `updated_at`),
    KEY `idx_amazon_return_erp_sales_return_invoice` (`tenant_id`, `amazon_connection_id`, `return_invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }
}