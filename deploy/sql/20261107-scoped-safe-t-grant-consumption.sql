-- Staged-only: do NOT run this as part of application request handling.
-- Requires authorized database change, backup, and a disposable restore test
-- before applying on production. Does NOT enable any SAFE-T write action.
CREATE TABLE IF NOT EXISTS `amazon_return_scoped_grant_consumptions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` BIGINT UNSIGNED NOT NULL,
    `amazon_connection_id` BIGINT UNSIGNED NOT NULL,
    `grant_number` SMALLINT UNSIGNED NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `consumed_at` DATETIME NULL,
    `quarantined_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_scoped_grant_once` (`tenant_id`, `amazon_connection_id`, `grant_number`),
    KEY `idx_scoped_grant_status` (`status`, `created_at`),
    CONSTRAINT `chk_scoped_grant_known` CHECK (`grant_number` IN (3556,3673,3846)),
    CONSTRAINT `chk_scoped_grant_state` CHECK (`status` IN ('RESERVED','CONSUMED','QUARANTINED')),
    CONSTRAINT `chk_scoped_grant_scope` CHECK (`tenant_id`=1 AND `amazon_connection_id`=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
