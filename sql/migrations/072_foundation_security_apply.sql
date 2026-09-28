-- 072_foundation_security_apply.sql
-- Jalankan SEKALI untuk menambah kolom MFA ke master_system_login.
-- Jika kolom sudah ada, akan error — gunakan tools/nas/apply_mfa_migration.sh untuk idempotent.

CREATE TABLE IF NOT EXISTS `auth_login_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `username` VARCHAR(120) NOT NULL,
  `failed_count` INT NOT NULL DEFAULT 0,
  `last_attempt_at` DATETIME NULL,
  `locked_until` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_attempt_ip_user` (`ip_address`, `username`),
  KEY `idx_auth_attempt_locked` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE master_system_login ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE master_system_login ADD COLUMN mfa_secret VARCHAR(128) NULL AFTER mfa_enabled;
ALTER TABLE master_system_login ADD COLUMN mfa_confirmed_at DATETIME NULL AFTER mfa_secret;
ALTER TABLE master_system_login ADD COLUMN mfa_backup_codes_hash LONGTEXT NULL AFTER mfa_confirmed_at;
