-- 161: HRL — hapus permission duplikat dari registry DB + matrix
--
-- Menghapus:
--   HRL.REQUEST_KENAIKAN_GAJI  → grant allow=1 dipindah ke HRL.REQ_KENAIKAN_GAJI_CREATE
--   HRL.REQUEST_REKRUTMEN      → grant allow=1 dipindah ke HRL.REQ_REKRUTMEN_CREATE
--   REG_ALKES_READ             → HRL.REG_ALKES_VIEW
--   REG_ALKES_WRITE            → HRL.REG_ALKES_EDIT
--   REG_ALKES_EXPORT           → HRL.REG_ALKES_EXPORT
--
-- WAJIB: canonical rows sudah ada di rbac_permissions (sync dari config/rbac_permissions.php).
-- Jalankan di NAS: mysql ... < sql/migrations/161_rbac_hrl_drop_duplicate_perms.sql

SET NAMES utf8mb4;

-- Pastikan kode kanonik sudah ada di rbac_permissions (RBAC Center → Sync Permissions).

START TRANSACTION;

-- ── rbac_dept_role_permissions: salin allow=1 ke kode kanonik (OR dengan baris yang ada)
INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REQ_KENAIKAN_GAJI_CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL.REQUEST_KENAIKAN_GAJI' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REQ_REKRUTMEN_CREATE', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'HRL.REQUEST_REKRUTMEN' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REG_ALKES_VIEW', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'REG_ALKES_READ' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REG_ALKES_EDIT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'REG_ALKES_WRITE' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_dept_role_permissions (dept_code, role_code, perm_code, allow_flag, created_at)
SELECT d.dept_code, d.role_code, 'HRL.REG_ALKES_EXPORT', 1, NOW()
FROM rbac_dept_role_permissions d
WHERE d.perm_code = 'REG_ALKES_EXPORT' AND d.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_dept_role_permissions.allow_flag, VALUES(allow_flag));

-- ── rbac_user_permissions
INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REQ_KENAIKAN_GAJI_CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL.REQUEST_KENAIKAN_GAJI' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REQ_REKRUTMEN_CREATE', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'HRL.REQUEST_REKRUTMEN' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REG_ALKES_VIEW', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'REG_ALKES_READ' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REG_ALKES_EDIT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'REG_ALKES_WRITE' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

INSERT INTO rbac_user_permissions (user_id, perm_code, allow_flag, created_at)
SELECT u.user_id, 'HRL.REG_ALKES_EXPORT', 1, NOW()
FROM rbac_user_permissions u
WHERE u.perm_code = 'REG_ALKES_EXPORT' AND u.allow_flag = 1
ON DUPLICATE KEY UPDATE allow_flag = GREATEST(rbac_user_permissions.allow_flag, VALUES(allow_flag));

-- Hapus baris matrix lama (sebelum hapus rbac_permissions)
DELETE FROM rbac_dept_role_permissions WHERE perm_code IN (
  'HRL.REQUEST_KENAIKAN_GAJI',
  'HRL.REQUEST_REKRUTMEN',
  'REG_ALKES_READ',
  'REG_ALKES_WRITE',
  'REG_ALKES_EXPORT'
);

DELETE FROM rbac_user_permissions WHERE perm_code IN (
  'HRL.REQUEST_KENAIKAN_GAJI',
  'HRL.REQUEST_REKRUTMEN',
  'REG_ALKES_READ',
  'REG_ALKES_WRITE',
  'REG_ALKES_EXPORT'
);

DELETE FROM rbac_permissions WHERE perm_code IN (
  'HRL.REQUEST_KENAIKAN_GAJI',
  'HRL.REQUEST_REKRUTMEN',
  'REG_ALKES_READ',
  'REG_ALKES_WRITE',
  'REG_ALKES_EXPORT'
);

COMMIT;
