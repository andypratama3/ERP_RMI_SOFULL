-- 075_tax_invoice_workflow.sql
-- P1: tax invoice workflow tables (idempotent)

CREATE TABLE IF NOT EXISTS `tax_profiles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `company_name` VARCHAR(200) NOT NULL,
  `company_npwp` VARCHAR(64) NOT NULL,
  `address` TEXT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tax_invoices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_invoice_ref` VARCHAR(120) NOT NULL,
  `tax_no` VARCHAR(80) NOT NULL,
  `tax_date` DATE NOT NULL,
  `status` ENUM('DRAFT','ISSUED','REVISED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `file_path` VARCHAR(255) NULL,
  `created_by` BIGINT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tax_invoice_no` (`tax_no`),
  KEY `idx_tax_invoice_sales_ref` (`sales_invoice_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tax_invoice_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tax_invoice_id` BIGINT UNSIGNED NOT NULL,
  `action_name` VARCHAR(60) NOT NULL,
  `action_by` BIGINT NULL,
  `action_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tax_invoice_logs_invoice` (`tax_invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
