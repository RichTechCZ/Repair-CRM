-- Columns and diagnostics required by the current application code.
-- The shared migration runner applies these statements idempotently.

ALTER TABLE `login_attempts`
  ADD COLUMN `username_hash` CHAR(64) NULL AFTER `ip`,
  ADD INDEX `idx_login_attempt_scope` (`ip`, `username_hash`, `created_at`);

ALTER TABLE `technicians`
  ADD COLUMN `engineer_rate` DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER `is_active`;

ALTER TABLE `invoices`
  ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

CREATE TABLE IF NOT EXISTS `system_errors` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `error_type` VARCHAR(64) NOT NULL DEFAULT 'system',
  `message` TEXT NOT NULL,
  `details` MEDIUMTEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_system_errors_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
