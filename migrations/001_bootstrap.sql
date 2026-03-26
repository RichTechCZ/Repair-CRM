-- Repair CRM - Database Schema
-- Version: 1.0
-- Description: Creates all required tables for the CRM system

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Migrations tracker
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `migrations` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration_name` VARCHAR(255)   NOT NULL UNIQUE,
    `executed_at`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Rate limiting
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `action_key` VARCHAR(100)  NOT NULL,
    `ip`         VARCHAR(45)   NOT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action_ip` (`action_key`, `ip`),
    INDEX `idx_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Login attempts (for rate limiting)
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip`         VARCHAR(45)   NOT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ip` (`ip`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Users (admin accounts)
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `users` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `username`   VARCHAR(50)   NOT NULL,
    `password`   VARCHAR(255)  NOT NULL,
    `full_name`  VARCHAR(100)  DEFAULT NULL,
    `role`       ENUM('admin','technician') DEFAULT 'admin',
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin user (password: admin - CHANGE AFTER FIRST LOGIN!)
INSERT INTO `users` (`username`, `password`, `full_name`, `role`) VALUES
('admin', '$2y$10$qafwiLAk9Osoxr.4UX/YCuO6m6TejA377VwyxMP1zakKWOIdV89Ay', 'Administrator', 'admin');

-- ═══════════════════════════════════════════════════════════════════════════════
-- Technicians
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `technicians` (
    `id`             INT(11)       NOT NULL AUTO_INCREMENT,
    `name`           VARCHAR(100)  NOT NULL,
    `username`       VARCHAR(50)   DEFAULT NULL,
    `password`       VARCHAR(255)  DEFAULT NULL,
    `phone`          VARCHAR(20)   DEFAULT NULL,
    `email`          VARCHAR(100)  DEFAULT NULL,
    `telegram_id`    VARCHAR(50)   DEFAULT NULL,
    `specialization` VARCHAR(100)  DEFAULT NULL,
    `role`           VARCHAR(50)   DEFAULT 'engineer',
    `is_active`      TINYINT(1)    DEFAULT 1,
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`      TIMESTAMP     NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Technician permissions
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `tech_permissions` (
    `id`            INT(11)       NOT NULL AUTO_INCREMENT,
    `technician_id` INT(11)       NOT NULL,
    `permission`    VARCHAR(50)   NOT NULL,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_perm` (`technician_id`, `permission`),
    CONSTRAINT `tech_permissions_ibfk_1` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Customers
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `customers` (
    `id`            INT(11)       NOT NULL AUTO_INCREMENT,
    `customer_type` ENUM('private','company') DEFAULT 'private',
    `first_name`    VARCHAR(50)   NOT NULL,
    `last_name`     VARCHAR(50)   NOT NULL,
    `ico`           VARCHAR(20)   DEFAULT NULL,
    `dic`           VARCHAR(20)   DEFAULT NULL,
    `company`       VARCHAR(100)  DEFAULT NULL,
    `phone`         VARCHAR(20)   DEFAULT NULL,
    `email`         VARCHAR(100)  DEFAULT NULL,
    `address`       TEXT          DEFAULT NULL,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Orders
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `orders` (
    `id`                   INT(11)       NOT NULL AUTO_INCREMENT,
    `customer_id`          INT(11)       NOT NULL,
    `device_type`          ENUM('Phone','Notebook','PC','Tablet','HDD','Computer','Other') NOT NULL,
    `order_type`           ENUM('Non-Warranty','Warranty') DEFAULT 'Non-Warranty',
    `device_model`         VARCHAR(100)  NOT NULL,
    `device_brand`         VARCHAR(100)  DEFAULT NULL,
    `serial_number`        VARCHAR(100)  DEFAULT NULL,
    `serial_number_2`      VARCHAR(100)  DEFAULT NULL,
    `appearance`           TEXT          DEFAULT NULL,
    `pin_code`             VARCHAR(50)   DEFAULT NULL,
    `priority`             ENUM('Normal','High') DEFAULT 'Normal',
    `problem_description`  TEXT          DEFAULT NULL,
    `technician_notes`     TEXT          DEFAULT NULL,
    `estimated_cost`       DECIMAL(10,2) DEFAULT NULL,
    `final_cost`           DECIMAL(10,2) DEFAULT NULL,
    `extra_expenses`       DECIMAL(10,2) DEFAULT 0.00,
    `status`               ENUM('New','In Progress','Waiting for Parts','Pending Approval','Completed','Collected','Cancelled') DEFAULT 'New',
    `technician_id`        INT(11)       DEFAULT NULL,
    `created_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `shipping_method`      VARCHAR(50)   DEFAULT NULL,
    `shipping_tracking`    VARCHAR(100)  DEFAULT NULL,
    `shipping_date`        DATETIME      DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `customer_id` (`customer_id`),
    KEY `technician_id` (`technician_id`),
    KEY `status` (`status`),
    KEY `created_at` (`created_at`),
    CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
    CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Order attachments (photos/files)
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `order_attachments` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `order_id`   INT(11)       NOT NULL,
    `file_path`  VARCHAR(255)  NOT NULL,
    `file_type`  VARCHAR(50)   DEFAULT NULL,
    `file_name`  VARCHAR(255)  DEFAULT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `order_id` (`order_id`),
    CONSTRAINT `order_attachments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Device brands
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `device_brands` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `brand_name` VARCHAR(100)  NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `brand_name` (`brand_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default brands
INSERT INTO `device_brands` (`brand_name`) VALUES
('Acer'), ('Apple'), ('Asus'), ('CUBOT'), ('Dell'), ('Google'),
('Honor'), ('HP'), ('HTC'), ('HUAWEI'), ('Lenovo'), ('LG'),
('Meizu'), ('Motorola'), ('MSI'), ('Nokia'), ('OnePlus'), ('Samsung'),
('Sony'), ('Toshiba'), ('Xiaomi'), ('ZTE'), ('Other')
ON DUPLICATE KEY UPDATE `brand_name` = `brand_name`;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Inventory (spare parts)
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `inventory` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `part_name`  VARCHAR(100)  NOT NULL,
    `sku`        VARCHAR(50)   DEFAULT NULL,
    `quantity`   INT(11)       DEFAULT 0,
    `cost_price` DECIMAL(10,2) DEFAULT NULL,
    `sale_price` DECIMAL(10,2) DEFAULT NULL,
    `min_stock`  INT(11)       DEFAULT 5,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Order items (parts used in orders)
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `order_items` (
    `id`           INT(11)       NOT NULL AUTO_INCREMENT,
    `order_id`     INT(11)       NOT NULL,
    `inventory_id` INT(11)       NOT NULL,
    `quantity`     INT(11)       DEFAULT 1,
    `price`        DECIMAL(10,2) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `order_id` (`order_id`),
    KEY `inventory_id` (`inventory_id`),
    CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
    CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Invoices
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `invoices` (
    `id`                   INT(11)       NOT NULL AUTO_INCREMENT,
    `invoice_number`       VARCHAR(50)   NOT NULL,
    `variable_symbol`      VARCHAR(50)   DEFAULT NULL,
    `order_id`             INT(11)       DEFAULT NULL,
    `customer_id`          INT(11)       NOT NULL,
    `date_issue`           DATE          NOT NULL,
    `date_tax`             DATE          NOT NULL,
    `date_due`             DATE          NOT NULL,
    `total_amount`         DECIMAL(15,2) NOT NULL,
    `is_vat_payer`         TINYINT(1)    DEFAULT 0,
    `vat_amount`           DECIMAL(15,2) DEFAULT 0.00,
    `currency`             VARCHAR(10)   DEFAULT 'Kc',
    `status`               ENUM('draft','issued','paid','overdue','cancelled') DEFAULT 'issued',
    `payment_method`       VARCHAR(50)   DEFAULT 'bank_transfer',
    `payment_date`         DATE          DEFAULT NULL,
    `pdf_path`             VARCHAR(255)  DEFAULT NULL,
    `created_at`           TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `invoice_type`         ENUM('invoice','credit_note') DEFAULT 'invoice',
    `parent_id`            INT(11)       DEFAULT NULL,
    `notes`                TEXT          DEFAULT NULL,
    `cust_name_override`   VARCHAR(255)  DEFAULT NULL,
    `cust_address_override` TEXT         DEFAULT NULL,
    `cust_ico_override`    VARCHAR(20)   DEFAULT NULL,
    `cust_dic_override`    VARCHAR(20)   DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `invoice_number` (`invoice_number`),
    KEY `order_id` (`order_id`),
    KEY `customer_id` (`customer_id`),
    KEY `fk_invoice_parent` (`parent_id`),
    CONSTRAINT `fk_invoice_parent` FOREIGN KEY (`parent_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
    CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
    CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- Invoice items
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `invoice_items` (
    `id`         INT(11)       NOT NULL AUTO_INCREMENT,
    `invoice_id` INT(11)       NOT NULL,
    `item_name`  VARCHAR(255)  NOT NULL,
    `quantity`   DECIMAL(10,2) DEFAULT 1.00,
    `unit`       VARCHAR(10)   DEFAULT 'ks',
    `price`      DECIMAL(15,2) NOT NULL,
    `vat_rate`   DECIMAL(5,2)  DEFAULT 21.00,
    PRIMARY KEY (`id`),
    KEY `invoice_id` (`invoice_id`),
    CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════════
-- System settings
-- ═══════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key`   VARCHAR(50)   NOT NULL,
    `setting_value` TEXT          DEFAULT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings (customize via Settings page)
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'My Repair Shop'),
('company_address', ''),
('company_phone', ''),
('currency', 'Kc'),
('language', 'ru'),
('next_order_number', '1001'),
('acc_company_name', ''),
('acc_address', ''),
('acc_ico', ''),
('acc_dic', ''),
('acc_bank_account', ''),
('acc_bank_name', ''),
('acc_iban', ''),
('acc_swift', ''),
('acc_is_vat_payer', '0'),
('acc_vat_rate', '21'),
('acc_invoice_prefix', '2026'),
('acc_invoice_next_number', '1')
ON DUPLICATE KEY UPDATE `setting_value` = `setting_value`;

SET FOREIGN_KEY_CHECKS=1;
