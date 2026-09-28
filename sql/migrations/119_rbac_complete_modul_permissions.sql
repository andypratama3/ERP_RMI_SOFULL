-- 119_rbac_complete_modul_permissions.sql
-- Permission lengkap: Jobs Monitor, Rate Limit, API Partner Keys, Chat Admin, Tools, Master Import.
-- Idempotent: aman dijalankan berulang.

-- SYSTEM
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('SYSTEM.JOBS_MONITOR', 'Jobs Monitor', 'SYSTEM', 'Monitoring worker queue, retry/run-now/cancel job.', 1),
('SYSTEM.RATE_LIMIT_MANAGE', 'Rate Limit Policies', 'SYSTEM', 'Konfigurasi threshold API write per scope.', 1),
('SYSTEM.ACCOUNT_READINESS', 'Account Readiness', 'SYSTEM', 'Cek kesiapan akun (MFA, password, dll).', 1),
('SYSTEM.API_PARTNER_KEYS', 'API Partner Keys', 'SYSTEM', 'Kelola API key untuk partner eksternal.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- MASTER Import
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('MASTER.IMPORT_PRODUCTS', 'Master Import Products', 'MASTER', 'Import data produk dari CSV.', 1),
('MASTER.IMPORT_CUSTOMERS', 'Master Import Customers', 'MASTER', 'Import data customer dari CSV.', 1),
('MASTER.IMPORT_VENDORS', 'Master Import Vendors', 'MASTER', 'Import data vendor dari CSV.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- CHAT
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('CHAT.ADMIN_SETTINGS', 'Chat Admin Settings', 'CHAT', 'Kelola pengaturan chat (reactions, retention, ACL, dll).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- TOOLS
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('TOOLS.BACKUP_MANAGE', 'Tools Backup Manage', 'TOOLS', 'Backup, restore, schedule, retention.', 1),
('TOOLS.READINESS_AUDIT', 'Tools Readiness Audit', 'TOOLS', 'Audit kesiapan deploy & cutover.', 1),
('TOOLS.SECURITY_AUDIT', 'Tools Security Audit', 'TOOLS', 'Static scan keamanan & konsistensi.', 1),
('TOOLS.REVIEW_KIT', 'Tools Review Kit', 'TOOLS', 'Review kit workspace & signoff.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- Assign ke ITC Manager
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('ITC', 'MANAGER', 'SYSTEM.JOBS_MONITOR', 1),
('ITC', 'MANAGER', 'SYSTEM.RATE_LIMIT_MANAGE', 1),
('ITC', 'MANAGER', 'SYSTEM.ACCOUNT_READINESS', 1),
('ITC', 'MANAGER', 'SYSTEM.API_PARTNER_KEYS', 1),
('ITC', 'MANAGER', 'TOOLS.BACKUP_MANAGE', 1),
('ITC', 'MANAGER', 'TOOLS.READINESS_AUDIT', 1),
('ITC', 'MANAGER', 'TOOLS.SECURITY_AUDIT', 1),
('ITC', 'MANAGER', 'TOOLS.REVIEW_KIT', 1),
('ITC', 'MANAGER', 'CHAT.ADMIN_SETTINGS', 1);

-- Assign ke PQP, CRM, SCM
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('PQP', 'MANAGER', 'MASTER.IMPORT_PRODUCTS', 1),
('CRM', 'MANAGER', 'MASTER.IMPORT_CUSTOMERS', 1),
('SCM', 'MANAGER', 'MASTER.IMPORT_VENDORS', 1);
