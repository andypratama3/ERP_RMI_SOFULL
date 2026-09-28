-- 115_rbac_dashboard_section_permissions.sql
-- Permission per section dashboard (SCM, Sales, Warehouse, dll) untuk pengaturan granular.
-- Idempotent: aman dijalankan berulang.

-- 1. Register permission baru
INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
VALUES
('DASHBOARD.SCM_VIEW', 'Dashboard SCM - View', 'DASHBOARD', 'Akses SCM Dashboard (Supply Chain, Import, Procurement).', 1),
('DASHBOARD.SALES_VIEW', 'Dashboard Sales - View', 'DASHBOARD', 'Akses Sales Dashboard.', 1),
('DASHBOARD.WAREHOUSE_VIEW', 'Dashboard Warehouse - View', 'DASHBOARD', 'Akses Warehouse/WQS Dashboard.', 1),
('DASHBOARD.FINANCE_VIEW', 'Dashboard Finance - View', 'DASHBOARD', 'Akses Finance Dashboard.', 1),
('DASHBOARD.PROCUREMENT_VIEW', 'Dashboard Procurement - View', 'DASHBOARD', 'Akses Procurement/Purchases Dashboard.', 1),
('DASHBOARD.REGULATORY_VIEW', 'Dashboard Regulatory - View', 'DASHBOARD', 'Akses Regulatory Dashboard.', 1),
('DASHBOARD.QUALITY_VIEW', 'Dashboard Quality - View', 'DASHBOARD', 'Akses Quality Dashboard.', 1),
('DASHBOARD.HRL_VIEW', 'Dashboard HRL - View', 'DASHBOARD', 'Akses HRL Dashboard.', 1),
('DASHBOARD.ITC_VIEW', 'Dashboard ITC - View', 'DASHBOARD', 'Akses ITC Dashboard.', 1),
('DASHBOARD.ACT_VIEW', 'Dashboard ACT - View', 'DASHBOARD', 'Akses ACT Dashboard.', 1),
('DASHBOARD.OWNER_VIEW', 'Dashboard Owner - View', 'DASHBOARD', 'Akses Owner Dashboard.', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

-- 2. Assign ke dept+role (idempotent)
INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('SCM', 'MANAGER', 'DASHBOARD.SCM_VIEW', 1),
('SCM', 'MANAGER', 'DASHBOARD.PROCUREMENT_VIEW', 1),
('SCM', 'STAFF', 'DASHBOARD.SCM_VIEW', 1),
('SCM', 'STAFF', 'DASHBOARD.PROCUREMENT_VIEW', 1),
('CRM', 'MANAGER', 'DASHBOARD.SALES_VIEW', 1),
('CRM', 'STAFF', 'DASHBOARD.SALES_VIEW', 1),
('WQS', 'MANAGER', 'DASHBOARD.WAREHOUSE_VIEW', 1),
('WQS', 'MANAGER', 'DASHBOARD.SCM_VIEW', 1),
('WQS', 'MANAGER', 'DASHBOARD.QUALITY_VIEW', 1),
('WQS', 'STAFF', 'DASHBOARD.WAREHOUSE_VIEW', 1),
('WQS', 'STAFF', 'DASHBOARD.SCM_VIEW', 1),
('WQS', 'STAFF', 'DASHBOARD.QUALITY_VIEW', 1),
('FIN', 'MANAGER', 'DASHBOARD.FINANCE_VIEW', 1),
('FIN', 'MANAGER', 'DASHBOARD.OWNER_VIEW', 1),
('FIN', 'STAFF', 'DASHBOARD.FINANCE_VIEW', 1),
('ACT', 'MANAGER', 'DASHBOARD.ACT_VIEW', 1),
('ACT', 'MANAGER', 'DASHBOARD.FINANCE_VIEW', 1),
('ACT', 'MANAGER', 'DASHBOARD.OWNER_VIEW', 1),
('ACT', 'STAFF', 'DASHBOARD.ACT_VIEW', 1),
('ACT', 'STAFF', 'DASHBOARD.FINANCE_VIEW', 1),
('HRL', 'MANAGER', 'DASHBOARD.HRL_VIEW', 1),
('HRL', 'STAFF', 'DASHBOARD.HRL_VIEW', 1),
('ITC', 'MANAGER', 'DASHBOARD.ITC_VIEW', 1),
('ITC', 'STAFF', 'DASHBOARD.ITC_VIEW', 1),
('PQP', 'MANAGER', 'DASHBOARD.SCM_VIEW', 1),
('PQP', 'MANAGER', 'DASHBOARD.PROCUREMENT_VIEW', 1),
('PQP', 'MANAGER', 'DASHBOARD.REGULATORY_VIEW', 1),
('PQP', 'MANAGER', 'DASHBOARD.QUALITY_VIEW', 1),
('PQP', 'STAFF', 'DASHBOARD.SCM_VIEW', 1),
('PQP', 'STAFF', 'DASHBOARD.PROCUREMENT_VIEW', 1),
('PQP', 'STAFF', 'DASHBOARD.REGULATORY_VIEW', 1),
('PQP', 'STAFF', 'DASHBOARD.QUALITY_VIEW', 1),
('MPR', 'MANAGER', 'DASHBOARD.SALES_VIEW', 1),
('MPR', 'STAFF', 'DASHBOARD.SALES_VIEW', 1),
('REG', 'MANAGER', 'DASHBOARD.REGULATORY_VIEW', 1),
('REG', 'STAFF', 'DASHBOARD.REGULATORY_VIEW', 1),
('BRANCH', 'STAFF', 'DASHBOARD.SALES_VIEW', 1),
('BRANCH', 'STAFF', 'DASHBOARD.SCM_VIEW', 1),
('BRANCH', 'STAFF', 'DASHBOARD.WAREHOUSE_VIEW', 1),
('BRANCH', 'STAFF', 'DASHBOARD.FINANCE_VIEW', 1);
