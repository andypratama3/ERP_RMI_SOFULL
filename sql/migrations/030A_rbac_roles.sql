-- 030_rbac_roles.sql
-- Tambahan tabel rbac_roles (compat) untuk daftar role.
-- Dibutuhkan oleh beberapa halaman RBAC yang membaca daftar role dari tabel.

CREATE TABLE IF NOT EXISTS rbac_roles (
  role_code VARCHAR(50) PRIMARY KEY,
  role_name VARCHAR(100) NOT NULL,
  description VARCHAR(255) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
