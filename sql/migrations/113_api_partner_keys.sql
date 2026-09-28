-- 113_api_partner_keys.sql
-- API Partner Keys untuk akses eksternal (partner)
-- Hak akses: ADMIN & SUPERADMIN only
-- Catatan: Jalankan 114_api_partner_keys_environment.sql untuk kolom environment (production/development)

CREATE TABLE IF NOT EXISTS `api_partner_keys` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `partner_name` VARCHAR(120) NOT NULL,
  `api_key_hash` VARCHAR(255) NOT NULL,
  `scopes` TEXT NULL COMMENT 'Comma-separated: sales.do.read,stock.items.read',
  `rate_limit_per_hour` INT UNSIGNED NOT NULL DEFAULT 1000,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` VARCHAR(80) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_used_at` DATETIME NULL,
  `note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_partner_name` (`partner_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
