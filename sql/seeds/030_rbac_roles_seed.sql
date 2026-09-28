-- 030_rbac_roles_seed.sql
-- Seed role dasar untuk tabel rbac_roles.

INSERT IGNORE INTO rbac_roles (role_code, role_name, description, is_active) VALUES
('SUPERADMIN','Super Admin','Full access',1),
('ADMIN','Admin','Admin harian',1),
('MANAGER','Manager','Manager departemen',1),
('STAFF','Staff','Staff operasional',1);
