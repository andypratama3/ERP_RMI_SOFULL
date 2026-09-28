-- 076_three_way_match_and_jobs.sql
-- P0/P1: 3-way match config + line linkage + simple queue jobs

CREATE TABLE IF NOT EXISTS `procurement_match_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_name` VARCHAR(80) NOT NULL,
  `qty_tolerance_pct` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `price_tolerance_pct` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_proc_match_rule_name` (`rule_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO procurement_match_rules (rule_name, qty_tolerance_pct, price_tolerance_pct, is_active)
SELECT 'DEFAULT', 0, 0, 1
WHERE NOT EXISTS (
  SELECT 1 FROM procurement_match_rules WHERE rule_name = 'DEFAULT'
);

CREATE TABLE IF NOT EXISTS `purchases_invoice_ap_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ap_id` INT NOT NULL,
  `po_item_id` INT NULL,
  `sku` VARCHAR(50) NULL,
  `qty` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `unit_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `line_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ap_lines_ap_id` (`ap_id`),
  KEY `idx_ap_lines_po_item` (`po_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_type` VARCHAR(100) NOT NULL,
  `payload_json` LONGTEXT NULL,
  `status` ENUM('PENDING','RUNNING','DONE','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` INT NOT NULL DEFAULT 0,
  `run_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_error` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_jobs_status_run_at` (`status`, `run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
