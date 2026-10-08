<?php

function crmMigrationTableExists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
    return $stmt ? (bool)$stmt->fetchColumn() : false;
}

function crmMigrationColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return $stmt ? (bool)$stmt->fetch(PDO::FETCH_ASSOC) : false;
}

function crmMigrationIndexExists(PDO $pdo, string $table, string $index): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $index)) {
        return false;
    }
    $stmt = $pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $pdo->quote($index));
    return $stmt ? (bool)$stmt->fetch(PDO::FETCH_ASSOC) : false;
}

function crmMigrationEnsureRegistry(PDO $pdo): array
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration_name` VARCHAR(255) NOT NULL UNIQUE,
            `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (crmMigrationTableExists($pdo, '_migrations')) {
        $legacy = $pdo->query('SELECT filename FROM _migrations')->fetchAll(PDO::FETCH_COLUMN);
        $insert = $pdo->prepare('INSERT IGNORE INTO migrations (migration_name) VALUES (?)');
        foreach ($legacy as $filename) {
            $insert->execute([$filename]);
        }
    }

    return $pdo->query('SELECT migration_name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
}

function crmMigrationMarkExecuted(PDO $pdo, string $migrationName): void
{
    $pdo->prepare('INSERT IGNORE INTO migrations (migration_name) VALUES (?)')->execute([$migrationName]);
}

function crmMigrationExecuteSql(PDO $pdo, string $sql): void
{
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

function crmApplyStatusOverhaulMigration(PDO $pdo): void
{
    if (crmMigrationTableExists($pdo, 'orders') && crmMigrationColumnExists($pdo, 'orders', 'status')) {
        $pdo->exec(
            "ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'New','In Progress','Waiting for Parts','Pending Approval','Completed','Collected','Cancelled',
                'Accepted','Diagnostics','Approval','In Repair','Ready','Issued','Issued Without Repair','Repair Cancelled'
            ) NOT NULL DEFAULT 'Accepted'"
        );

        $statusMap = [
            'New' => 'Accepted',
            'Pending Approval' => 'Approval',
            'In Progress' => 'In Repair',
            'Waiting for Parts' => 'In Repair',
            'Completed' => 'Ready',
            'Collected' => 'Issued',
            'Cancelled' => 'Repair Cancelled',
        ];
        $update = $pdo->prepare('UPDATE orders SET status = ? WHERE status = ?');
        foreach ($statusMap as $old => $new) {
            $update->execute([$new, $old]);
        }

        $pdo->exec(
            "ALTER TABLE `orders` MODIFY COLUMN `status` ENUM(
                'Accepted','Diagnostics','Approval','In Repair','Ready','Issued','Issued Without Repair','Repair Cancelled'
            ) NOT NULL DEFAULT 'Accepted'"
        );
        if (!crmMigrationColumnExists($pdo, 'orders', 'cancellation_reason')) {
            $pdo->exec('ALTER TABLE `orders` ADD COLUMN `cancellation_reason` TEXT DEFAULT NULL AFTER `status`');
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `order_status_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `old_status` VARCHAR(50) NOT NULL,
            `new_status` VARCHAR(50) NOT NULL,
            `changed_by` INT NULL,
            `changed_role` VARCHAR(20) NULL,
            `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_status_log_order_changed` (`order_id`, `changed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `device_models` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `brand` VARCHAR(100) NOT NULL,
            `model_name` VARCHAR(200) NOT NULL,
            `usage_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_brand` (`brand`),
            INDEX `idx_usage_count` (`usage_count`),
            INDEX `idx_model_name` (`model_name`),
            UNIQUE KEY `unique_brand_model` (`brand`, `model_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    if (!crmMigrationColumnExists($pdo, 'device_models', 'usage_count')) {
        $pdo->exec('ALTER TABLE `device_models` ADD COLUMN `usage_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `model_name`');
    }
    foreach (['idx_usage_count' => 'usage_count', 'idx_model_name' => 'model_name'] as $index => $column) {
        if (!crmMigrationIndexExists($pdo, 'device_models', $index)) {
            $pdo->exec("CREATE INDEX `{$index}` ON `device_models` (`{$column}`)");
        }
    }
    if (!crmMigrationIndexExists($pdo, 'device_models', 'unique_brand_model')) {
        $pdo->exec('CREATE UNIQUE INDEX `unique_brand_model` ON `device_models` (`brand`, `model_name`)');
    }

    if (crmMigrationTableExists($pdo, 'orders')) {
        $pdo->exec(
            "INSERT INTO `device_models` (`brand`, `model_name`, `usage_count`)
             SELECT UPPER(TRIM(`device_brand`)), TRIM(`device_model`), COUNT(*)
             FROM `orders`
             WHERE TRIM(COALESCE(`device_brand`, '')) <> ''
               AND TRIM(COALESCE(`device_model`, '')) <> ''
             GROUP BY UPPER(TRIM(`device_brand`)), TRIM(`device_model`)
             ON DUPLICATE KEY UPDATE `usage_count` = VALUES(`usage_count`)"
        );
    }

    if (crmMigrationTableExists($pdo, 'order_items')) {
        if (crmMigrationColumnExists($pdo, 'order_items', 'inventory_id')) {
            $pdo->exec('ALTER TABLE `order_items` MODIFY COLUMN `inventory_id` INT(11) NULL');
        }
        if (!crmMigrationColumnExists($pdo, 'order_items', 'part_name')) {
            $pdo->exec('ALTER TABLE `order_items` ADD COLUMN `part_name` VARCHAR(255) NULL AFTER `inventory_id`');
        }
        if (!crmMigrationColumnExists($pdo, 'order_items', 'source')) {
            $pdo->exec('ALTER TABLE `order_items` ADD COLUMN `source` VARCHAR(255) NULL AFTER `part_name`');
        }
    }
}

function crmApplyMyInvoiceMigration(PDO $pdo): void
{
    if (crmMigrationTableExists($pdo, 'customers')) {
        if (!crmMigrationColumnExists($pdo, 'customers', 'myinvoice_client_id')) {
            $pdo->exec('ALTER TABLE `customers` ADD COLUMN `myinvoice_client_id` INT NULL AFTER `address`');
        }
        if (!crmMigrationIndexExists($pdo, 'customers', 'idx_customers_myinvoice_client_id')) {
            $pdo->exec('ALTER TABLE `customers` ADD KEY `idx_customers_myinvoice_client_id` (`myinvoice_client_id`)');
        }
    }

    if (crmMigrationTableExists($pdo, 'invoices')) {
        $columns = [
            'myinvoice_invoice_id' => 'INT NULL AFTER `pdf_path`',
            'myinvoice_status' => 'VARCHAR(30) NULL AFTER `myinvoice_invoice_id`',
            'myinvoice_synced_at' => 'DATETIME NULL AFTER `myinvoice_status`',
            'myinvoice_sync_error' => 'TEXT NULL AFTER `myinvoice_synced_at`',
        ];
        foreach ($columns as $column => $definition) {
            if (!crmMigrationColumnExists($pdo, 'invoices', $column)) {
                $pdo->exec("ALTER TABLE `invoices` ADD COLUMN `{$column}` {$definition}");
            }
        }
        if (!crmMigrationIndexExists($pdo, 'invoices', 'uniq_invoices_myinvoice_invoice_id')) {
            $pdo->exec('ALTER TABLE `invoices` ADD UNIQUE KEY `uniq_invoices_myinvoice_invoice_id` (`myinvoice_invoice_id`)');
        }
    }

    if (crmMigrationTableExists($pdo, 'system_settings')) {
        $pdo->exec(
            "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
                ('acc_auto_create_invoice', '1'),
                ('myinvoice_enabled', '1'),
                ('myinvoice_auto_issue', '1'),
                ('myinvoice_api_base_url', 'https://fakturace.43.157.31.121.sslip.io'),
                ('myinvoice_default_country_id', '1'),
                ('myinvoice_default_street', '-'),
                ('myinvoice_default_city', 'Praha'),
                ('myinvoice_default_zip', '11000')"
        );
    }
}

function crmApplyProductionHardeningMigration(PDO $pdo): void
{
    if (crmMigrationTableExists($pdo, 'login_attempts')) {
        if (!crmMigrationColumnExists($pdo, 'login_attempts', 'username_hash')) {
            $pdo->exec('ALTER TABLE `login_attempts` ADD COLUMN `username_hash` CHAR(64) NULL AFTER `ip`');
        }
        if (!crmMigrationIndexExists($pdo, 'login_attempts', 'idx_login_attempt_scope')) {
            $pdo->exec(
                'CREATE INDEX `idx_login_attempt_scope` ON `login_attempts` (`ip`, `username_hash`, `created_at`)'
            );
        }
    }
    if (crmMigrationTableExists($pdo, 'technicians') && !crmMigrationColumnExists($pdo, 'technicians', 'engineer_rate')) {
        $pdo->exec('ALTER TABLE `technicians` ADD COLUMN `engineer_rate` DECIMAL(5,2) NOT NULL DEFAULT 50.00 AFTER `is_active`');
    }
    if (crmMigrationTableExists($pdo, 'invoices') && !crmMigrationColumnExists($pdo, 'invoices', 'updated_at')) {
        $pdo->exec(
            'ALTER TABLE `invoices` ADD COLUMN `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`'
        );
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `system_errors` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `error_type` VARCHAR(64) NOT NULL DEFAULT 'system',
            `message` TEXT NOT NULL,
            `details` MEDIUMTEXT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_system_errors_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function crmApplySearchSensitiveReportingMigration(PDO $pdo): void
{
    if (crmMigrationTableExists($pdo, 'customers')) {
        if (!crmMigrationColumnExists($pdo, 'customers', 'phone_search')) {
            $pdo->exec('ALTER TABLE `customers` ADD COLUMN `phone_search` VARCHAR(32) NULL AFTER `phone`');
        }
        $customerPhones = $pdo->query('SELECT `id`, `phone` FROM `customers`');
        $updatePhoneSearch = $pdo->prepare(
            'UPDATE `customers` SET `phone_search` = ? WHERE `id` = ?'
        );
        foreach ($customerPhones->fetchAll(PDO::FETCH_ASSOC) as $customerPhone) {
            $normalizedPhone = preg_replace('/\D+/', '', (string)($customerPhone['phone'] ?? ''));
            $updatePhoneSearch->execute([
                is_string($normalizedPhone) ? $normalizedPhone : '',
                (int)$customerPhone['id'],
            ]);
        }
        if (!crmMigrationIndexExists($pdo, 'customers', 'idx_customers_phone_search')) {
            $pdo->exec('CREATE INDEX `idx_customers_phone_search` ON `customers` (`phone_search`)');
        }
        if (!crmMigrationIndexExists($pdo, 'customers', 'ft_customers_search')) {
            $pdo->exec(
                'CREATE FULLTEXT INDEX `ft_customers_search` ON `customers` (`first_name`, `last_name`, `company`, `phone`)'
            );
        }
    }

    if (crmMigrationTableExists($pdo, 'orders')) {
        $pdo->exec('ALTER TABLE `orders` MODIFY COLUMN `pin_code` TEXT NULL');
        if (!crmMigrationIndexExists($pdo, 'orders', 'ft_orders_search')) {
            $pdo->exec(
                'CREATE FULLTEXT INDEX `ft_orders_search` ON `orders` (`device_brand`, `device_model`, `problem_description`, `serial_number`, `serial_number_2`)'
            );
        }
        if (!crmMigrationIndexExists($pdo, 'orders', 'idx_orders_status_technician')) {
            $pdo->exec('CREATE INDEX `idx_orders_status_technician` ON `orders` (`status`, `technician_id`)');
        }

        $selectPins = $pdo->query(
            "SELECT `id`, `pin_code` FROM `orders`
             WHERE `pin_code` IS NOT NULL AND TRIM(`pin_code`) <> ''"
        );
        $updatePin = $pdo->prepare('UPDATE `orders` SET `pin_code` = ? WHERE `id` = ?');
        foreach ($selectPins->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stored = (string)$row['pin_code'];
            if (!crmSensitiveDataIsEncrypted($stored)) {
                $updatePin->execute([crmEncryptSensitiveValue($stored), (int)$row['id']]);
            }
        }
    }

    if (
        crmMigrationTableExists($pdo, 'technicians') &&
        !crmMigrationIndexExists($pdo, 'technicians', 'ft_technicians_search')
    ) {
        $pdo->exec('CREATE FULLTEXT INDEX `ft_technicians_search` ON `technicians` (`name`)');
    }

    if (crmMigrationTableExists($pdo, 'order_status_log')) {
        if (!crmMigrationIndexExists($pdo, 'order_status_log', 'idx_status_log_status_changed')) {
            $pdo->exec(
                'CREATE INDEX `idx_status_log_status_changed` ON `order_status_log` (`new_status`, `changed_at`, `order_id`)'
            );
        }
        if (crmMigrationTableExists($pdo, 'orders')) {
            $pdo->exec(
                "INSERT INTO `order_status_log`
                    (`order_id`, `old_status`, `new_status`, `changed_by`, `changed_role`, `changed_at`)
                 SELECT o.id, o.status, o.status, NULL, 'migration', COALESCE(o.shipping_date, o.created_at)
                 FROM `orders` o
                 WHERE NOT EXISTS (
                     SELECT 1
                     FROM `order_status_log` l
                     WHERE l.order_id = o.id AND l.new_status = o.status
                 )"
            );
        }
    }

    if (
        crmMigrationTableExists($pdo, 'invoices') &&
        !crmMigrationIndexExists($pdo, 'invoices', 'idx_invoices_reporting')
    ) {
        $pdo->exec(
            'CREATE INDEX `idx_invoices_reporting` ON `invoices` (`order_id`, `invoice_type`, `status`, `payment_date`, `id`)'
        );
    }
}

/**
 * Adds opaque 8-char public_status_token used by customer QR status links.
 */
function crmApplyPublicStatusTokenMigration(PDO $pdo): void
{
    if (!crmMigrationTableExists($pdo, 'orders')) {
        return;
    }

    if (!crmMigrationColumnExists($pdo, 'orders', 'public_status_token')) {
        $pdo->exec(
            "ALTER TABLE `orders`
             ADD COLUMN `public_status_token` CHAR(8) NULL DEFAULT NULL AFTER `id`"
        );
    }

    if (!crmMigrationIndexExists($pdo, 'orders', 'uq_orders_public_status_token')) {
        // Deduplicate any pre-existing values before unique index (fresh installs have none).
        $pdo->exec(
            "UPDATE `orders` o
             JOIN (
                 SELECT public_status_token, MIN(id) AS keep_id
                 FROM `orders`
                 WHERE public_status_token IS NOT NULL AND public_status_token != ''
                 GROUP BY public_status_token
                 HAVING COUNT(*) > 1
             ) d ON o.public_status_token = d.public_status_token AND o.id <> d.keep_id
             SET o.public_status_token = NULL"
        );
        $pdo->exec(
            'ALTER TABLE `orders` ADD UNIQUE KEY `uq_orders_public_status_token` (`public_status_token`)'
        );
    }
}

/**
 * Adds telegram_bot_states table and optional users.telegram_id.
 */
function crmApplyTelegramBotStateMigration(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `telegram_bot_states` (
            `telegram_id` VARCHAR(50) NOT NULL,
            `state`       VARCHAR(50) NOT NULL,
            `order_id`    INT(11)     DEFAULT NULL,
            `temp_data`   TEXT        DEFAULT NULL,
            `updated_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`telegram_id`),
            KEY `idx_tg_state_updated` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (crmMigrationTableExists($pdo, 'users') && !crmMigrationColumnExists($pdo, 'users', 'telegram_id')) {
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `telegram_id` VARCHAR(50) DEFAULT NULL AFTER `role`');
    }
}

function crmRunMigrations(PDO $pdo, string $projectDir): array
{
    $migrationFiles = glob(rtrim($projectDir, '/\\') . '/migrations/*.sql') ?: [];
    sort($migrationFiles);
    $results = [];

    try {
        $executed = crmMigrationEnsureRegistry($pdo);
        foreach ($migrationFiles as $migrationFile) {
            $basename = basename($migrationFile);
            if (in_array($basename, $executed, true)) {
                $results[] = ['file' => $basename, 'status' => 'skipped'];
                continue;
            }

            try {
                if (
                    $basename === '001_bootstrap.sql' &&
                    crmMigrationTableExists($pdo, 'users') &&
                    crmMigrationTableExists($pdo, 'customers') &&
                    crmMigrationTableExists($pdo, 'orders')
                ) {
                    $status = 'skipped_existing_schema';
                } elseif ($basename === '002_status_overhaul.sql') {
                    crmApplyStatusOverhaulMigration($pdo);
                    $status = 'ok';
                } elseif ($basename === '002_myinvoice_integration.sql') {
                    crmApplyMyInvoiceMigration($pdo);
                    $status = 'ok';
                } elseif ($basename === '003_production_hardening.sql') {
                    crmApplyProductionHardeningMigration($pdo);
                    $status = 'ok';
                } elseif ($basename === '004_search_sensitive_reporting.sql') {
                    crmApplySearchSensitiveReportingMigration($pdo);
                    $status = 'ok';
                } elseif ($basename === '005_public_status_token.sql') {
                    crmApplyPublicStatusTokenMigration($pdo);
                    $status = 'ok';
                } elseif ($basename === '006_telegram_bot_state.sql') {
                    crmApplyTelegramBotStateMigration($pdo);
                    $status = 'ok';
                } else {
                    $sql = file_get_contents($migrationFile);
                    if ($sql === false) {
                        throw new RuntimeException('Unable to read migration file.');
                    }
                    crmMigrationExecuteSql($pdo, $sql);
                    $status = 'ok';
                }

                crmMigrationMarkExecuted($pdo, $basename);
                $executed[] = $basename;
                $results[] = ['file' => $basename, 'status' => $status];
            } catch (Throwable $e) {
                $results[] = ['file' => $basename, 'status' => 'error', 'message' => $e->getMessage()];
                break;
            }
        }
    } catch (Throwable $e) {
        $results[] = ['status' => 'error', 'message' => 'Migration system error: ' . $e->getMessage()];
    }

    return $results;
}

function crmMigrationResultsContainError(array $results): bool
{
    foreach ($results as $result) {
        if (($result['status'] ?? '') === 'error') {
            return true;
        }
    }
    return false;
}
