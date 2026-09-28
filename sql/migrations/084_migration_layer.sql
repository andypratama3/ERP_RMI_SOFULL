-- 084_migration_layer.sql
-- Data migration staging + mapping + logging (idempotent)

CREATE TABLE IF NOT EXISTS `migration_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(30) NOT NULL,
  `mode` VARCHAR(20) NOT NULL DEFAULT 'cutover',
  `from_date` DATE NULL,
  `to_date` DATE NULL,
  `is_dry_run` TINYINT(1) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
  `file_hash` VARCHAR(128) NULL,
  `summary_json` LONGTEXT NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  `executed_by` VARCHAR(120) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_migration_runs_source` (`source`),
  KEY `idx_migration_runs_status` (`status`),
  KEY `idx_migration_runs_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `migration_errors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `entity` VARCHAR(60) NOT NULL,
  `source_key` VARCHAR(190) NULL,
  `error_message` VARCHAR(1000) NOT NULL,
  `payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_migration_errors_run` (`run_id`),
  KEY `idx_migration_errors_entity` (`entity`),
  CONSTRAINT `fk_migration_errors_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `migration_target_keys` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `target_table` VARCHAR(80) NOT NULL,
  `target_id` BIGINT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `migration_run_id` BIGINT UNSIGNED NULL,
  `migrated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration_target_key` (`target_table`, `source_system`, `source_key`),
  KEY `idx_migration_target_run` (`migration_run_id`),
  CONSTRAINT `fk_migration_target_run`
    FOREIGN KEY (`migration_run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `migration_sales_receipts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `receipt_no` VARCHAR(120) NOT NULL,
  `receipt_date` DATE NOT NULL,
  `sales_invoice_no` VARCHAR(120) NOT NULL,
  `customer_code` VARCHAR(80) NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'IDR',
  `method` VARCHAR(30) NULL,
  `note` VARCHAR(255) NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `migration_run_id` BIGINT UNSIGNED NULL,
  `migrated_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_migration_sales_receipts_source` (`source_system`, `source_key`),
  KEY `idx_migration_sales_receipts_invoice` (`sales_invoice_no`),
  CONSTRAINT `fk_migration_sales_receipts_run`
    FOREIGN KEY (`migration_run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_coa` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `account_code` VARCHAR(40) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `account_type` VARCHAR(30) NOT NULL,
  `parent_code` VARCHAR(40) NULL,
  `is_postable` TINYINT(1) NOT NULL DEFAULT 1,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  `currency` VARCHAR(10) NULL,
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_coa_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_coa_run` (`run_id`),
  CONSTRAINT `fk_stg_coa_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_tax_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `tax_code` VARCHAR(40) NOT NULL,
  `tax_name` VARCHAR(255) NOT NULL,
  `tax_type` VARCHAR(30) NOT NULL DEFAULT 'PPN',
  `rate_percent` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_tax_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_tax_run` (`run_id`),
  CONSTRAINT `fk_stg_tax_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_parties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `entity_type` ENUM('CUSTOMER','VENDOR') NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `party_code` VARCHAR(80) NOT NULL,
  `party_name` VARCHAR(255) NOT NULL,
  `npwp` VARCHAR(50) NULL,
  `email` VARCHAR(150) NULL,
  `phone` VARCHAR(80) NULL,
  `address` VARCHAR(255) NULL,
  `city` VARCHAR(120) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_parties_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_parties_run` (`run_id`),
  KEY `idx_stg_parties_type` (`entity_type`),
  CONSTRAINT `fk_stg_parties_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `item_code` VARCHAR(80) NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `unit_code` VARCHAR(50) NULL,
  `category` VARCHAR(100) NULL,
  `price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_items_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_items_run` (`run_id`),
  CONSTRAINT `fk_stg_items_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `unit_code` VARCHAR(50) NOT NULL,
  `unit_name` VARCHAR(120) NOT NULL,
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_units_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_units_run` (`run_id`),
  CONSTRAINT `fk_stg_units_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `master_units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_code` VARCHAR(50) NOT NULL,
  `unit_name` VARCHAR(120) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `source_system` VARCHAR(30) NULL,
  `source_key` VARCHAR(190) NULL,
  `migration_run_id` BIGINT UNSIGNED NULL,
  `migrated_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_master_units_code` (`unit_code`),
  UNIQUE KEY `uq_master_units_source` (`source_system`, `source_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_warehouses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `warehouse_code` VARCHAR(50) NOT NULL,
  `warehouse_name` VARCHAR(120) NOT NULL,
  `city` VARCHAR(120) NULL,
  `address` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_wh_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_wh_run` (`run_id`),
  CONSTRAINT `fk_stg_wh_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_opening_balances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `entity_type` ENUM('GL','AR','AP','STOCK','BANK') NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `balance_date` DATE NOT NULL,
  `ref_code` VARCHAR(120) NULL,
  `account_code` VARCHAR(40) NULL,
  `party_code` VARCHAR(80) NULL,
  `item_code` VARCHAR(80) NULL,
  `warehouse_code` VARCHAR(50) NULL,
  `qty` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'IDR',
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_opening_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_opening_run` (`run_id`),
  KEY `idx_stg_opening_entity` (`entity_type`),
  CONSTRAINT `fk_stg_opening_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `stg_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `source_system` VARCHAR(30) NOT NULL,
  `doc_type` ENUM(
    'SALES_INVOICE',
    'SALES_PAYMENT',
    'PURCHASE_INVOICE',
    'PURCHASE_PAYMENT',
    'GENERAL_JOURNAL',
    'INVENTORY_ADJUSTMENT'
  ) NOT NULL,
  `source_key` VARCHAR(190) NOT NULL,
  `doc_no` VARCHAR(120) NOT NULL,
  `doc_date` DATE NOT NULL,
  `party_code` VARCHAR(80) NULL,
  `warehouse_code` VARCHAR(50) NULL,
  `item_code` VARCHAR(80) NULL,
  `account_code` VARCHAR(40) NULL,
  `qty` DECIMAL(18,4) NOT NULL DEFAULT 0,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'IDR',
  `status` VARCHAR(30) NULL,
  `raw_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stg_docs_source_key` (`source_system`, `source_key`),
  KEY `idx_stg_docs_run` (`run_id`),
  KEY `idx_stg_docs_type` (`doc_type`),
  KEY `idx_stg_docs_date` (`doc_date`),
  CONSTRAINT `fk_stg_docs_run`
    FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `map_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_code` VARCHAR(40) NULL,
  `target_account_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map_accounts_src` (`source`, `source_id`),
  KEY `idx_map_accounts_target` (`target_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `map_customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_code` VARCHAR(80) NULL,
  `target_customer_id` BIGINT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map_customers_src` (`source`, `source_id`),
  KEY `idx_map_customers_target` (`target_customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `map_vendors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_code` VARCHAR(80) NULL,
  `target_vendor_id` BIGINT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map_vendors_src` (`source`, `source_id`),
  KEY `idx_map_vendors_target` (`target_vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `map_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_code` VARCHAR(80) NULL,
  `target_item_id` BIGINT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map_items_src` (`source`, `source_id`),
  KEY `idx_map_items_target` (`target_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `map_warehouses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` VARCHAR(30) NOT NULL,
  `source_id` VARCHAR(120) NOT NULL,
  `source_code` VARCHAR(50) NULL,
  `target_warehouse_id` BIGINT NOT NULL,
  `target_office_code` VARCHAR(20) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_map_warehouses_src` (`source`, `source_id`),
  KEY `idx_map_warehouses_target` (`target_warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
