-- 106_rbac_branch_dept.sql
-- Dept BRANCH untuk karyawan tunggal di kantor/depo yang melakukan semua proses.
-- Level: Staff (aman, bukan Manager).

INSERT INTO master_departements (id, dept_code, dept_name, level_type, office_code, status, created_at, updated_at)
SELECT 2000, 'BRANCH', 'Branch / Depo Agent', 'Staff', NULL, 'active', NOW(), NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM master_departements WHERE dept_code = 'BRANCH' LIMIT 1);
