-- Application migration: secure device PINs, indexed global search, and stable
-- status/reporting indexes. The shared PHP migration runner performs the
-- idempotent DDL, encrypts existing PIN values with CRM_DATA_ENCRYPTION_KEY,
-- and backfills a stable current-status transition when legacy history is
-- absent or only partially populated.

ALTER TABLE `customers`
  ADD COLUMN `phone_search` VARCHAR(32) NULL AFTER `phone`,
  ADD INDEX `idx_customers_phone_search` (`phone_search`),
  ADD FULLTEXT INDEX `ft_customers_search` (`first_name`, `last_name`, `company`, `phone`);

ALTER TABLE `orders`
  MODIFY COLUMN `pin_code` TEXT NULL,
  ADD FULLTEXT INDEX `ft_orders_search`
    (`device_brand`, `device_model`, `problem_description`, `serial_number`, `serial_number_2`),
  ADD INDEX `idx_orders_status_technician` (`status`, `technician_id`);

ALTER TABLE `technicians`
  ADD FULLTEXT INDEX `ft_technicians_search` (`name`);

ALTER TABLE `order_status_log`
  ADD INDEX `idx_status_log_status_changed` (`new_status`, `changed_at`, `order_id`);

ALTER TABLE `invoices`
  ADD INDEX `idx_invoices_reporting` (`order_id`, `invoice_type`, `status`, `payment_date`, `id`);
