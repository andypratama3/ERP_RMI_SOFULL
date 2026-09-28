-- 123_rbac_owner_to_sys.sql
-- Konsistensi RBAC: OWNER diganti SYS (SYS = ADMIN & SUPERADMIN).
-- Idempotent: aman dijalankan berulang.

-- 1. Update user superadmin: role owner → sys
UPDATE master_system_login
SET role = 'sys', level = 'SUPERADMIN', department = 'SYS', updated_at = NOW()
WHERE username IN ('superadmin', 'owner') AND (role = 'owner' OR role = 'OWNER');

-- 2. Update rbac_dept_role_permissions: OWNER → SYS (jika ada)
UPDATE rbac_dept_role_permissions SET role_code = 'SYS' WHERE role_code = 'OWNER';

-- 3. Hapus policy MFA OWNER (deprecated, diganti SYS)
DELETE FROM auth_mfa_policies WHERE role_code = 'OWNER';
