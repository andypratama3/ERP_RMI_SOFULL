-- 074_bank_reconciliation.sql
-- P1: Bank reconciliation core tables (idempotent)

CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_code` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `gl_account_id` BIGINT UNSIGNED NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'IDR',
  `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_accounts_code` (`account_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bank_statements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_account_id` BIGINT UNSIGNED NOT NULL,
  `period_key` VARCHAR(7) NOT NULL,
  `opening_balance` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `closing_balance` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `uploaded_file` VARCHAR(255) NULL,
  `created_by` BIGINT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_statement_period` (`bank_account_id`, `period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bank_statement_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `statement_id` BIGINT UNSIGNED NOT NULL,
  `txn_date` DATE NOT NULL,
  `description` VARCHAR(255) NULL,
  `reference_no` VARCHAR(100) NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `txn_type` ENUM('DEBIT','CREDIT') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_statement_lines_statement` (`statement_id`),
  KEY `idx_bank_statement_lines_date` (`txn_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bank_reconciliations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bank_account_id` BIGINT UNSIGNED NOT NULL,
  `period_key` VARCHAR(7) NOT NULL,
  `status` ENUM('DRAFT','RECONCILED','LOCKED') NOT NULL DEFAULT 'DRAFT',
  `created_by` BIGINT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_recon_period` (`bank_account_id`, `period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `bank_recon_matches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reconciliation_id` BIGINT UNSIGNED NOT NULL,
  `statement_line_id` BIGINT UNSIGNED NOT NULL,
  `erp_txn_type` VARCHAR(50) NOT NULL,
  `erp_txn_id` BIGINT NOT NULL,
  `matched_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bank_recon_match_recon` (`reconciliation_id`),
  KEY `idx_bank_recon_match_stmt` (`statement_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
