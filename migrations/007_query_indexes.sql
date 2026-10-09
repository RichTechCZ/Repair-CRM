-- Indexes for hot read paths found in the 2026-10 production audit.
-- Executed by crmApplyQueryIndexesMigration() in includes/migration_runner.php,
-- which skips indexes that already exist (safe on partially upgraded schemas).
CREATE INDEX `idx_orders_serial` ON `orders` (`serial_number`);
CREATE INDEX `idx_orders_serial2` ON `orders` (`serial_number_2`);
CREATE INDEX `idx_orders_customer_tech` ON `orders` (`customer_id`, `technician_id`);
CREATE INDEX `idx_orders_tech_created` ON `orders` (`technician_id`, `created_at`);
CREATE INDEX `idx_customers_name` ON `customers` (`last_name`, `first_name`);
CREATE INDEX `idx_inventory_part_name` ON `inventory` (`part_name`);
CREATE INDEX `idx_invoices_created` ON `invoices` (`created_at`, `id`);
CREATE INDEX `idx_status_log_order_status` ON `order_status_log` (`order_id`, `new_status`, `changed_at`);
CREATE INDEX `idx_rl_key_created` ON `rate_limits` (`action_key`, `created_at`);
CREATE INDEX `idx_login_user_created` ON `login_attempts` (`username_hash`, `created_at`);
