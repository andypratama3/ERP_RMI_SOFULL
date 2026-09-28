-- Migration 127: DASHBOARD.BRANCH_VIEW — permission khusus Branch Dashboard
-- Branch Dashboard (dashboards/branch/branch_dashboard.php) punya permission sendiri, konsisten dengan section lain.

INSERT INTO rbac_permissions (perm_code, perm_name, module, description, is_active)
VALUES ('DASHBOARD.BRANCH_VIEW', 'Dashboard Branch - View', 'DASHBOARD', 'Akses Branch Dashboard (landing staff cabang).', 1)
ON DUPLICATE KEY UPDATE perm_name=VALUES(perm_name), module=VALUES(module), description=VALUES(description), is_active=1;

INSERT IGNORE INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag) VALUES
('BRANCH', 'STAFF', 'DASHBOARD.BRANCH_VIEW', 1);
