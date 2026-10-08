-- Telegram bot state management for FSM and multi-step dialogs.
CREATE TABLE IF NOT EXISTS `telegram_bot_states` (
    `telegram_id` VARCHAR(50) NOT NULL,
    `state`       VARCHAR(50) NOT NULL,
    `order_id`    INT(11)     DEFAULT NULL,
    `temp_data`   TEXT        DEFAULT NULL,
    `updated_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`telegram_id`),
    KEY `idx_tg_state_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
