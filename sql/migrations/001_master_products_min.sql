-- 001_master_products_min.sql
-- Minimal & kompatibel untuk ERP_RMI_SOFULL (MySQL 8+ / MariaDB 10.4+)
-- Tujuan: memastikan table master_products tersedia untuk modul Master + Stock/WQS.

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `master_products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku` VARCHAR(64) NOT NULL,
  `products_name` VARCHAR(255) NOT NULL,
  `uom` VARCHAR(32) NULL,
  `category` VARCHAR(100) NULL,
  `brand` VARCHAR(100) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_master_products_sku` (`sku`),
  KEY `idx_master_products_active` (`is_active`),
  KEY `idx_master_products_name` (`products_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

-- DOWN (rollback manual):
-- SET FOREIGN_KEY_CHECKS=0;
-- DROP TABLE IF EXISTS `master_products`;
-- SET FOREIGN_KEY_CHECKS=1;
