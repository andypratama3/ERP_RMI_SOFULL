-- 073_gl_engine_core.sql
-- P1: GL core engine tables (idempotent)

CREATE TABLE IF NOT EXISTS `gl_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(32) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `account_type` ENUM('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE') NOT NULL,
  `parent_id` BIGINT UNSIGNED NULL,
  `is_postable` TINYINT(1) NOT NULL DEFAULT 1,
  `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gl_accounts_code` (`code`),
  KEY `idx_gl_accounts_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gl_journal_headers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `journal_no` VARCHAR(60) NOT NULL,
  `journal_date` DATE NOT NULL,
  `source_module` VARCHAR(60) NOT NULL,
  `source_event` VARCHAR(80) NOT NULL,
  `source_ref` VARCHAR(120) NOT NULL,
  `description` VARCHAR(255) NULL,
  `status` ENUM('DRAFT','POSTED','VOIDED') NOT NULL DEFAULT 'DRAFT',
  `created_by` BIGINT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gl_journal_no` (`journal_no`),
  UNIQUE KEY `uq_gl_source_ref` (`source_module`, `source_event`, `source_ref`),
  KEY `idx_gl_journal_date` (`journal_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gl_journal_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `header_id` BIGINT UNSIGNED NOT NULL,
  `line_no` INT NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  `dr_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `cr_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `memo` VARCHAR(255) NULL,
  `cost_center` VARCHAR(100) NULL,
  `project_code` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gl_lines_header` (`header_id`),
  KEY `idx_gl_lines_account` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gl_periods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `year_no` INT NOT NULL,
  `month_no` INT NOT NULL,
  `status` ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  `closed_at` DATETIME NULL,
  `closed_by` BIGINT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gl_period` (`year_no`, `month_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gl_posting_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_code` VARCHAR(80) NOT NULL,
  `posted_at` DATETIME NOT NULL,
  `posted_by` BIGINT NULL,
  `note` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_gl_batches_posted_at` (`posted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gl_mappings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_name` VARCHAR(60) NOT NULL,
  `event_name` VARCHAR(80) NOT NULL,
  `debit_account_id` BIGINT UNSIGNED NOT NULL,
  `credit_account_id` BIGINT UNSIGNED NOT NULL,
  `rule_json` LONGTEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_gl_mapping_event` (`module_name`, `event_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
