-- 079_mfa_policy_controls.sql
-- MFA policy per role/department + optional global defaults (idempotent)

CREATE TABLE IF NOT EXISTS `auth_mfa_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_code` VARCHAR(50) NOT NULL DEFAULT '*',
  `dept_code` VARCHAR(50) NOT NULL DEFAULT '*',
  `require_mfa` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mfa_policy_role_dept` (`role_code`, `dept_code`),
  KEY `idx_mfa_policy_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'OWNER', '*', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='OWNER' AND dept_code='*'
);

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'SUPERADMIN', '*', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='SUPERADMIN' AND dept_code='*'
);

INSERT INTO auth_mfa_policies (role_code, dept_code, require_mfa, is_active, created_at, updated_at)
SELECT 'ADMIN', '*', 1, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM auth_mfa_policies WHERE role_code='ADMIN' AND dept_code='*'
);
