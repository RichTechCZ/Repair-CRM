-- Full data wipe for the currently selected MySQL database.
-- Keeps table structure, removes all rows from all tables.
-- WARNING: This also deletes users, technicians, settings, logs, invoices, orders, and customers.
-- Before running, select the target database in phpMyAdmin/Adminer/MySQL client.
--
-- Example:
--   USE repair_crm;
--   SOURCE clear_database.sql;

DELIMITER $$

DROP PROCEDURE IF EXISTS wipe_current_database$$

CREATE PROCEDURE wipe_current_database()
BEGIN
    DECLARE done INT DEFAULT 0;
    DECLARE table_name_value VARCHAR(255);

    DECLARE table_cursor CURSOR FOR
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_type = 'BASE TABLE';

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    IF DATABASE() IS NULL OR DATABASE() = '' THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'No database selected. Run USE your_database_name first.';
    END IF;

    SET FOREIGN_KEY_CHECKS = 0;

    OPEN table_cursor;

    read_loop: LOOP
        FETCH table_cursor INTO table_name_value;
        IF done = 1 THEN
            LEAVE read_loop;
        END IF;

        SET @truncate_sql = CONCAT(
            'TRUNCATE TABLE `',
            REPLACE(table_name_value, '`', '``'),
            '`'
        );

        PREPARE stmt FROM @truncate_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END LOOP;

    CLOSE table_cursor;

    SET FOREIGN_KEY_CHECKS = 1;
END$$

DELIMITER ;

CALL wipe_current_database();
DROP PROCEDURE IF EXISTS wipe_current_database;

-- Optional: if you want a clean admin account after wipe, uncomment and adjust:
-- INSERT INTO users (username, password, full_name, role)
-- VALUES (
--   'admin',
--   '$2y$10$JOJ00rpkxeenGbPMSh2v8usg7M5CMkWLoBkly9jTFG.Kd1tHxAWqS',
--   'Administrator',
--   'admin'
-- );
--
-- Login for the optional block above:
-- username: admin
-- password: admin
