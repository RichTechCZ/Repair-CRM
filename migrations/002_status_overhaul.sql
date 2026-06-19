-- Migration: 002_status_overhaul.sql
-- Description: Replaces the old 7-status ENUM with 8 new statuses,
--              migrates existing data, adds cancellation_reason column,
--              and creates the device_models lookup table.

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 1: Expand ENUM to include BOTH old and new values (safe transition)
-- ═══════════════════════════════════════════════════════════════════════════════
ALTER TABLE `orders`
  MODIFY COLUMN `status`
    ENUM(
      'New','In Progress','Waiting for Parts','Pending Approval','Completed','Collected','Cancelled',
      'Accepted','Diagnostics','Approval','In Repair','Ready','Issued','Issued Without Repair','Repair Cancelled'
    ) NOT NULL DEFAULT 'Accepted';

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 2: Migrate existing data from old values to new values
-- ═══════════════════════════════════════════════════════════════════════════════
UPDATE `orders` SET `status` = 'Accepted'           WHERE `status` = 'New';
UPDATE `orders` SET `status` = 'Approval'            WHERE `status` = 'Pending Approval';
UPDATE `orders` SET `status` = 'In Repair'           WHERE `status` = 'In Progress';
UPDATE `orders` SET `status` = 'In Repair'           WHERE `status` = 'Waiting for Parts';
UPDATE `orders` SET `status` = 'Ready'               WHERE `status` = 'Completed';
UPDATE `orders` SET `status` = 'Issued'              WHERE `status` = 'Collected';
UPDATE `orders` SET `status` = 'Repair Cancelled'    WHERE `status` = 'Cancelled';

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 3: Migrate status_log entries (old_status / new_status are VARCHAR)
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `order_status_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `old_status` VARCHAR(50) NOT NULL,
    `new_status` VARCHAR(50) NOT NULL,
    `changed_by` INT NULL,
    `changed_role` VARCHAR(20) NULL,
    `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `order_status_log` SET `old_status` = 'Accepted'           WHERE `old_status` = 'New';
UPDATE `order_status_log` SET `new_status` = 'Accepted'           WHERE `new_status` = 'New';
UPDATE `order_status_log` SET `old_status` = 'Approval'            WHERE `old_status` = 'Pending Approval';
UPDATE `order_status_log` SET `new_status` = 'Approval'            WHERE `new_status` = 'Pending Approval';
UPDATE `order_status_log` SET `old_status` = 'In Repair'           WHERE `old_status` = 'In Progress';
UPDATE `order_status_log` SET `new_status` = 'In Repair'           WHERE `new_status` = 'In Progress';
UPDATE `order_status_log` SET `old_status` = 'In Repair'           WHERE `old_status` = 'Waiting for Parts';
UPDATE `order_status_log` SET `new_status` = 'In Repair'           WHERE `new_status` = 'Waiting for Parts';
UPDATE `order_status_log` SET `old_status` = 'Ready'               WHERE `old_status` = 'Completed';
UPDATE `order_status_log` SET `new_status` = 'Ready'               WHERE `new_status` = 'Completed';
UPDATE `order_status_log` SET `old_status` = 'Issued'              WHERE `old_status` = 'Collected';
UPDATE `order_status_log` SET `new_status` = 'Issued'              WHERE `new_status` = 'Collected';
UPDATE `order_status_log` SET `old_status` = 'Repair Cancelled'    WHERE `old_status` = 'Cancelled';
UPDATE `order_status_log` SET `new_status` = 'Repair Cancelled'    WHERE `new_status` = 'Cancelled';

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 4: Shrink ENUM to only new values (remove old ones)
-- ═══════════════════════════════════════════════════════════════════════════════
ALTER TABLE `orders`
  MODIFY COLUMN `status`
    ENUM(
      'Accepted','Diagnostics','Approval','In Repair','Ready','Issued','Issued Without Repair','Repair Cancelled'
    ) NOT NULL DEFAULT 'Accepted';

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 5: Add cancellation_reason column for terminal statuses
-- ═══════════════════════════════════════════════════════════════════════════════
ALTER TABLE `orders`
  ADD COLUMN `cancellation_reason` TEXT DEFAULT NULL AFTER `status`;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 6: Create device_models lookup table for autocomplete
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `device_models` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `brand`      VARCHAR(100) NOT NULL,
    `model_name` VARCHAR(200) NOT NULL,
    `usage_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_brand` (`brand`),
    INDEX `idx_usage_count` (`usage_count`),
    INDEX `idx_model_name` (`model_name`),
    UNIQUE KEY `unique_brand_model` (`brand`, `model_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 7: Seed device_models with common Apple models
-- ═══════════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `device_models` (`brand`, `model_name`) VALUES
  ('APPLE', 'iPhone 11'),
  ('APPLE', 'iPhone 11 Pro'),
  ('APPLE', 'iPhone 11 Pro Max'),
  ('APPLE', 'iPhone 12'),
  ('APPLE', 'iPhone 12 Mini'),
  ('APPLE', 'iPhone 12 Pro'),
  ('APPLE', 'iPhone 12 Pro Max'),
  ('APPLE', 'iPhone 13'),
  ('APPLE', 'iPhone 13 Mini'),
  ('APPLE', 'iPhone 13 Pro'),
  ('APPLE', 'iPhone 13 Pro Max'),
  ('APPLE', 'iPhone 14'),
  ('APPLE', 'iPhone 14 Plus'),
  ('APPLE', 'iPhone 14 Pro'),
  ('APPLE', 'iPhone 14 Pro Max'),
  ('APPLE', 'iPhone 15'),
  ('APPLE', 'iPhone 15 Plus'),
  ('APPLE', 'iPhone 15 Pro'),
  ('APPLE', 'iPhone 15 Pro Max'),
  ('APPLE', 'iPhone 16'),
  ('APPLE', 'iPhone 16 Plus'),
  ('APPLE', 'iPhone 16 Pro'),
  ('APPLE', 'iPhone 16 Pro Max'),
  ('APPLE', 'iPhone SE (2nd gen)'),
  ('APPLE', 'iPhone SE (3rd gen)'),
  ('APPLE', 'iPad Air (4th gen)'),
  ('APPLE', 'iPad Air (5th gen)'),
  ('APPLE', 'iPad Pro 11"'),
  ('APPLE', 'iPad Pro 12.9"'),
  ('APPLE', 'iPad (9th gen)'),
  ('APPLE', 'iPad (10th gen)'),
  ('APPLE', 'iPad Mini (6th gen)'),
  ('APPLE', 'MacBook Air 13" M1'),
  ('APPLE', 'MacBook Air 13" M2'),
  ('APPLE', 'MacBook Air 15" M2'),
  ('APPLE', 'MacBook Air 13" M3'),
  ('APPLE', 'MacBook Pro 14" M3'),
  ('APPLE', 'MacBook Pro 14" M3 Pro'),
  ('APPLE', 'MacBook Pro 16" M3 Pro'),
  ('APPLE', 'MacBook Pro 16" M3 Max'),
  ('APPLE', 'Apple Watch SE'),
  ('APPLE', 'Apple Watch Series 8'),
  ('APPLE', 'Apple Watch Series 9'),
  ('APPLE', 'Apple Watch Ultra 2'),
  ('APPLE', 'AirPods Pro (2nd gen)'),
  ('APPLE', 'AirPods Max'),
  ('SAMSUNG', 'Galaxy S23'),
  ('SAMSUNG', 'Galaxy S23+'),
  ('SAMSUNG', 'Galaxy S23 Ultra'),
  ('SAMSUNG', 'Galaxy S24'),
  ('SAMSUNG', 'Galaxy S24+'),
  ('SAMSUNG', 'Galaxy S24 Ultra'),
  ('SAMSUNG', 'Galaxy A54'),
  ('SAMSUNG', 'Galaxy A34'),
  ('SAMSUNG', 'Galaxy A14'),
  ('SAMSUNG', 'Galaxy Z Flip5'),
  ('SAMSUNG', 'Galaxy Z Fold5'),
  ('SAMSUNG', 'Galaxy Tab S9'),
  ('XIAOMI', 'Redmi Note 13'),
  ('XIAOMI', 'Redmi Note 13 Pro'),
  ('XIAOMI', '14 Ultra'),
  ('XIAOMI', '13T Pro'),
  ('HUAWEI', 'P60 Pro'),
  ('HUAWEI', 'Mate 60 Pro'),
  ('GOOGLE', 'Pixel 8'),
  ('GOOGLE', 'Pixel 8 Pro');

INSERT INTO `device_models` (`brand`, `model_name`, `usage_count`)
SELECT
  UPPER(TRIM(`device_brand`)) AS `brand`,
  TRIM(`device_model`) AS `model_name`,
  COUNT(*) AS `usage_count`
FROM `orders`
WHERE TRIM(COALESCE(`device_brand`, '')) <> ''
  AND TRIM(COALESCE(`device_model`, '')) <> ''
GROUP BY UPPER(TRIM(`device_brand`)), TRIM(`device_model`)
ON DUPLICATE KEY UPDATE `usage_count` = `device_models`.`usage_count` + VALUES(`usage_count`);

-- ═══════════════════════════════════════════════════════════════════════════════
-- Step 8: Allow manual order items without warehouse inventory rows
-- ═══════════════════════════════════════════════════════════════════════════════
ALTER TABLE `order_items`
  MODIFY COLUMN `inventory_id` INT(11) NULL,
  ADD COLUMN `part_name` VARCHAR(255) NULL AFTER `inventory_id`,
  ADD COLUMN `source` VARCHAR(255) NULL AFTER `part_name`;
