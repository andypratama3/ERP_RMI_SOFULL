-- 072_foundation_security.sql
-- P0: login throttling + MFA columns (idempotent)

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

SET @db := DATABASE();

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='master_system_login' AND column_name='mfa_enabled'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER status'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='master_system_login' AND column_name='mfa_secret'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN mfa_secret VARCHAR(128) NULL AFTER mfa_enabled'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='master_system_login' AND column_name='mfa_confirmed_at'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN mfa_confirmed_at DATETIME NULL AFTER mfa_secret'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='master_system_login' AND column_name='mfa_backup_codes_hash'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN mfa_backup_codes_hash LONGTEXT NULL AFTER mfa_confirmed_at'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
