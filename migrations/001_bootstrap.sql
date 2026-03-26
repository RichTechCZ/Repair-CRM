-- Migration: create migrations tracker + rate_limits
-- Run this first to bootstrap the migrations system.

CREATE TABLE IF NOT EXISTS `migrations` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration_name` VARCHAR(255)   NOT NULL UNIQUE,
    `executed_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate-limiting table (used by includes/rate_limit.php)
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action_key` VARCHAR(100)  NOT NULL,
    `ip`         VARCHAR(45)   NOT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action_ip` (`action_key`, `ip`),
    INDEX `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
