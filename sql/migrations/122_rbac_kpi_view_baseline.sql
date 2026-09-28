-- 122_rbac_kpi_view_baseline.sql
-- Assign KPI.VIEW ke semua dept+role (baseline, sama seperti CHAT.VIEW).
-- KPI.VIEW sudah terdaftar di rbac_permissions (migration 117).
-- Idempotent: aman dijalankan berulang.

INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('ACT', 'MANAGER', 'KPI.VIEW', 1),
('ACT', 'STAFF', 'KPI.VIEW', 1),
('CRM', 'MANAGER', 'KPI.VIEW', 1),
('CRM', 'STAFF', 'KPI.VIEW', 1),
('FIN', 'MANAGER', 'KPI.VIEW', 1),
('FIN', 'STAFF', 'KPI.VIEW', 1),
('HRL', 'MANAGER', 'KPI.VIEW', 1),
('HRL', 'STAFF', 'KPI.VIEW', 1),
('ITC', 'MANAGER', 'KPI.VIEW', 1),
('ITC', 'STAFF', 'KPI.VIEW', 1),
('MPR', 'MANAGER', 'KPI.VIEW', 1),
('MPR', 'STAFF', 'KPI.VIEW', 1),
('PQP', 'MANAGER', 'KPI.VIEW', 1),
('PQP', 'STAFF', 'KPI.VIEW', 1),
('SCM', 'MANAGER', 'KPI.VIEW', 1),
('SCM', 'STAFF', 'KPI.VIEW', 1),
('WQS', 'MANAGER', 'KPI.VIEW', 1),
('WQS', 'STAFF', 'KPI.VIEW', 1),
('REG', 'MANAGER', 'KPI.VIEW', 1),
('REG', 'STAFF', 'KPI.VIEW', 1),
('BRANCH', 'STAFF', 'KPI.VIEW', 1),
('SYS', 'MANAGER', 'KPI.VIEW', 1),
('SYS', 'STAFF', 'KPI.VIEW', 1);
