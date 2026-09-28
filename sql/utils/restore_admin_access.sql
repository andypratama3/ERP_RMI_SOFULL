-- Restore hak akses admin (bukan staff)
-- admin, administrator, superadmin = administrator, bukan staff
-- Jalankan: mysql -u root -p erp_rmi_sofull < sql/utils/restore_admin_access.sql

UPDATE master_system_login
SET role = 'admin', level = 'ADMIN', department = 'SYS', updated_at = NOW()
WHERE username IN ('admin', 'administrator');

UPDATE master_system_login
SET role = 'sys', level = 'SUPERADMIN', department = 'SYS', updated_at = NOW()
WHERE username = 'superadmin';
