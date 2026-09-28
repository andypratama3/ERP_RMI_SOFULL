-- 120_rbac_hrl_process_permissions.sql
-- Permission HRL Process (VIEW, EDIT, DELETE) untuk modul hrl_process.
-- Idempotent: aman dijalankan berulang.

-- 1. Register permission baru
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
VALUES
('HRL.PROCESS_VIEW', 'HRL Process - View', 'HRL', 'Akses modul HRL Process (tower, request, download).', 1),
('HRL.PROCESS_EDIT', 'HRL Process - Edit', 'HRL', 'Buat, edit, submit request HRL Process. Approve sesuai role/dept.', 1),
('HRL.PROCESS_DELETE', 'HRL Process - Delete', 'HRL', 'Hapus/batalkan request HRL Process (soft delete).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- 2. Assign ke dept+role (idempotent)
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
-- HRL
('HRL', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('HRL', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('HRL', 'MANAGER', 'HRL.PROCESS_DELETE', 1),
('HRL', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('HRL', 'STAFF', 'HRL.PROCESS_EDIT', 1),
-- FIN (DELETE untuk cancel/batalkan request)
('FIN', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('FIN', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('FIN', 'MANAGER', 'HRL.PROCESS_DELETE', 1),
('FIN', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('FIN', 'STAFF', 'HRL.PROCESS_EDIT', 1),
-- ACT, CRM, MPR, SCM, WQS, PQP, ITC, REG, BRANCH
('ACT', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('ACT', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('ACT', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('ACT', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('CRM', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('CRM', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('CRM', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('CRM', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('MPR', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('MPR', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('MPR', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('MPR', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('SCM', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('SCM', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('SCM', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('SCM', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('WQS', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('WQS', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('WQS', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('WQS', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('PQP', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('PQP', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('PQP', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('PQP', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('ITC', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('ITC', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('ITC', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('ITC', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('REG', 'MANAGER', 'HRL.PROCESS_VIEW', 1),
('REG', 'MANAGER', 'HRL.PROCESS_EDIT', 1),
('REG', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('REG', 'STAFF', 'HRL.PROCESS_EDIT', 1),
('BRANCH', 'STAFF', 'HRL.PROCESS_VIEW', 1),
('BRANCH', 'STAFF', 'HRL.PROCESS_EDIT', 1);
