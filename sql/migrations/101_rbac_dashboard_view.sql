-- 101_rbac_dashboard_view.sql
-- Lengkapi RBAC: permission baru + seed ke dept+role yang ada.
-- Idempotent: aman dijalankan berulang.

-- 1. Register permissions baru (jika belum ada)
INSERT IGNORE INTO rbac_permissions (perm_code, perm_name, module, description, is_active) VALUES
('DASHBOARD.VIEW', 'Dashboard Center - View', 'DASHBOARD', 'Akses Dashboard Center.', 1),
('DASHBOARD.FINANCE_DETAIL', 'Dashboard Finance Detail', 'DASHBOARD', 'Dashboard Detail Excel-style.', 1),
('DASHBOARD.OWNER_SUMMARY', 'Dashboard Owner Executive Summary', 'DASHBOARD', 'Ringkasan bisnis Owner.', 1),
('SALES.KPI_VIEW', 'Sales KPI & SLA View', 'SALES', 'Lihat KPI DO, SLA, audit DO.', 1),
('HRL.IMPORT_REKENING', 'HRL Import Rekening', 'HRL', 'Import rekening bank karyawan.', 1);

-- 2. DASHBOARD.VIEW ke semua dept + MANAGER/STAFF
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag)
SELECT DISTINCT dept_code, role_code, 'DASHBOARD.VIEW', 1
FROM rbac_dept_role_permissions WHERE role_code IN ('MANAGER', 'STAFF');

-- 3. DASHBOARD.FINANCE_DETAIL, DASHBOARD.OWNER_SUMMARY untuk FIN, ACT
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('FIN', 'MANAGER', 'DASHBOARD.FINANCE_DETAIL', 1),
('FIN', 'MANAGER', 'DASHBOARD.OWNER_SUMMARY', 1),
('FIN', 'STAFF', 'DASHBOARD.FINANCE_DETAIL', 1),
('ACT', 'MANAGER', 'DASHBOARD.FINANCE_DETAIL', 1),
('ACT', 'MANAGER', 'DASHBOARD.OWNER_SUMMARY', 1),
('ACT', 'STAFF', 'DASHBOARD.FINANCE_DETAIL', 1);

-- 4. SALES.KPI_VIEW untuk CRM, MPR, ACT
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('CRM', 'MANAGER', 'SALES.KPI_VIEW', 1),
('MPR', 'MANAGER', 'SALES.KPI_VIEW', 1),
('ACT', 'MANAGER', 'SALES.KPI_VIEW', 1);

-- 5. HRL.IMPORT_REKENING untuk HRL
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('HRL', 'MANAGER', 'HRL.IMPORT_REKENING', 1);
