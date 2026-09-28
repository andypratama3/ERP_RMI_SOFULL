-- 033A_fix_admin_role.sql
-- Pastikan admin & superadmin punya role ADMIN/SUPERADMIN (bukan staff)

UPDATE master_system_login
SET role = 'admin', level = 'ADMIN', department = 'SYS', updated_at = NOW()
WHERE username IN ('admin', 'administrator') AND (role != 'admin' OR level != 'ADMIN' OR level IS NULL);

UPDATE master_system_login
SET role = 'sys', level = 'SUPERADMIN', department = 'SYS', updated_at = NOW()
WHERE username = 'superadmin' AND (role != 'sys' OR level != 'SUPERADMIN' OR level IS NULL);
