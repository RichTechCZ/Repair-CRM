ALTER TABLE `customers`
    ADD COLUMN `myinvoice_client_id` INT NULL AFTER `address`,
    ADD KEY `idx_customers_myinvoice_client_id` (`myinvoice_client_id`);

ALTER TABLE `invoices`
    ADD COLUMN `myinvoice_invoice_id` INT NULL AFTER `pdf_path`,
    ADD COLUMN `myinvoice_status` VARCHAR(30) NULL AFTER `myinvoice_invoice_id`,
    ADD COLUMN `myinvoice_synced_at` DATETIME NULL AFTER `myinvoice_status`,
    ADD COLUMN `myinvoice_sync_error` TEXT NULL AFTER `myinvoice_synced_at`,
    ADD UNIQUE KEY `uniq_invoices_myinvoice_invoice_id` (`myinvoice_invoice_id`);

INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
('acc_auto_create_invoice', '1'),
('myinvoice_enabled', '1'),
('myinvoice_auto_issue', '1'),
('myinvoice_api_base_url', 'http://fakturace.43.157.31.121.sslip.io'),
('myinvoice_default_country_id', '1'),
('myinvoice_default_street', '-'),
('myinvoice_default_city', 'Praha'),
('myinvoice_default_zip', '11000')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
