-- 116_rbac_hrl_pqp_permissions.sql
-- Permission HRL (Reg Alkes, Compliance) dan PQP untuk modul hrl_reg_alkes.
-- Idempotent: aman dijalankan berulang.

-- 1. Register permission baru
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
VALUES
('HRL.VIEW', 'HRL - View', 'HRL', 'Akses modul HRL (dokumen, reg alkes, compliance).', 1),
('HRL.REG_ALKES_VIEW', 'HRL Reg Alkes - View', 'HRL', 'Lihat data registrasi alat kesehatan.', 1),
('HRL.COMPLIANCE_EXPORT', 'HRL Compliance Export', 'HRL', 'Export laporan compliance reg alkes (CSV/Excel).', 1),
('PQP.VIEW', 'PQP - View', 'PQP', 'Akses modul PQP (produk, quality, reg alkes).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- 2. Assign ke dept+role (idempotent)
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('HRL', 'MANAGER', 'HRL.VIEW', 1),
('HRL', 'MANAGER', 'HRL.REG_ALKES_VIEW', 1),
('HRL', 'MANAGER', 'HRL.COMPLIANCE_EXPORT', 1),
('HRL', 'STAFF', 'HRL.VIEW', 1),
('HRL', 'STAFF', 'HRL.REG_ALKES_VIEW', 1),
('PQP', 'MANAGER', 'PQP.VIEW', 1),
('PQP', 'MANAGER', 'HRL.REG_ALKES_VIEW', 1),
('PQP', 'MANAGER', 'HRL.COMPLIANCE_EXPORT', 1),
('PQP', 'STAFF', 'PQP.VIEW', 1),
('PQP', 'STAFF', 'HRL.REG_ALKES_VIEW', 1),
('REG', 'MANAGER', 'HRL.REG_ALKES_VIEW', 1),
('REG', 'MANAGER', 'HRL.COMPLIANCE_EXPORT', 1),
('REG', 'STAFF', 'HRL.REG_ALKES_VIEW', 1);
