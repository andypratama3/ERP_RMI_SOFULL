-- 080_jobs_rate_limit_mfa_bypass.sql
-- Queue controls + API rate limit + MFA bypass tickets (idempotent)

CREATE TABLE IF NOT EXISTS `api_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_key` VARCHAR(80) NOT NULL,
  `actor_key` VARCHAR(120) NOT NULL,
  `counter` INT NOT NULL DEFAULT 0,
  `window_started_at` DATETIME NOT NULL,
  `reset_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_api_rate_scope_actor` (`scope_key`,`actor_key`),
  KEY `idx_api_rate_reset` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `auth_mfa_bypass_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `reason` TEXT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mfa_bypass_user` (`user_id`),
  KEY `idx_mfa_bypass_exp` (`expires_at`),
  KEY `idx_mfa_bypass_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
