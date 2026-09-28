-- 118_rbac_system_mfa_permissions.sql
-- Permission MFA di modul SYSTEM untuk RBAC Center.
-- Idempotent: aman dijalankan berulang.

INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('SYSTEM.MFA_POLICY_MANAGE', 'MFA Policy Manage', 'SYSTEM', 'Kelola policy MFA per role/department (wajib MFA).', 1),
('SYSTEM.MFA_BYPASS_MANAGE', 'MFA Bypass Manage', 'SYSTEM', 'Kelola MFA bypass tickets (approve/reject/revoke).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- Assign ke ITC Manager
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('ITC', 'MANAGER', 'SYSTEM.MFA_POLICY_MANAGE', 1),
('ITC', 'MANAGER', 'SYSTEM.MFA_BYPASS_MANAGE', 1);
