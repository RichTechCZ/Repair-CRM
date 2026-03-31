-- Restore or reset the admin account in the currently selected database.
-- Default login after running this script:
--   username: admin
--   password: admin123
--
-- Usage:
--   USE repair_crm;
--   SOURCE restore_admin_password.sql;

UPDATE users
SET
    password = '$2y$10$bb0RyKEaWT9fWnk2FIJCHuYg7F2Nsb.dxJ71MxbMcY3dAN1Ca2eP2',
    full_name = 'Administrator',
    role = 'admin'
WHERE username = 'admin';

INSERT INTO users (username, password, full_name, role)
SELECT
    'admin',
    '$2y$10$bb0RyKEaWT9fWnk2FIJCHuYg7F2Nsb.dxJ71MxbMcY3dAN1Ca2eP2',
    'Administrator',
    'admin'
WHERE NOT EXISTS (
    SELECT 1
    FROM users
    WHERE username = 'admin'
);
