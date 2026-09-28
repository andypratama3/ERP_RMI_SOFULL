-- 121_rbac_chat_permissions.sql
-- Permission CHAT (VIEW, ADMIN_SETTINGS) untuk modul chat.
-- Idempotent: aman dijalankan berulang.

-- 1. Register permission CHAT.VIEW (CHAT.ADMIN_SETTINGS sudah ada dari migration sebelumnya)
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
VALUES
('CHAT.VIEW', 'Chat - View', 'CHAT', 'Akses modul Internal Chat (baca, kirim pesan).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- 2. Assign CHAT.VIEW ke semua dept+role standar
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('ACT', 'MANAGER', 'CHAT.VIEW', 1),
('ACT', 'STAFF', 'CHAT.VIEW', 1),
('CRM', 'MANAGER', 'CHAT.VIEW', 1),
('CRM', 'STAFF', 'CHAT.VIEW', 1),
('FIN', 'MANAGER', 'CHAT.VIEW', 1),
('FIN', 'STAFF', 'CHAT.VIEW', 1),
('HRL', 'MANAGER', 'CHAT.VIEW', 1),
('HRL', 'STAFF', 'CHAT.VIEW', 1),
('ITC', 'MANAGER', 'CHAT.VIEW', 1),
('ITC', 'STAFF', 'CHAT.VIEW', 1),
('MPR', 'MANAGER', 'CHAT.VIEW', 1),
('MPR', 'STAFF', 'CHAT.VIEW', 1),
('PQP', 'MANAGER', 'CHAT.VIEW', 1),
('PQP', 'STAFF', 'CHAT.VIEW', 1),
('SCM', 'MANAGER', 'CHAT.VIEW', 1),
('SCM', 'STAFF', 'CHAT.VIEW', 1),
('WQS', 'MANAGER', 'CHAT.VIEW', 1),
('WQS', 'STAFF', 'CHAT.VIEW', 1),
('REG', 'MANAGER', 'CHAT.VIEW', 1),
('REG', 'STAFF', 'CHAT.VIEW', 1),
('BRANCH', 'STAFF', 'CHAT.VIEW', 1),
('SYS', 'MANAGER', 'CHAT.VIEW', 1),
('SYS', 'STAFF', 'CHAT.VIEW', 1);
