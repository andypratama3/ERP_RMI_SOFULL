-- 105_bank_accounts_rekening_perusahaan.sql
-- Fix: Schema bank_accounts untuk halaman Rekening Perusahaan (company_bank_accounts.php)
-- Single source of truth. Idempotent.
-- Jalankan: mysql -u user -p database < sql/migrations/105_bank_accounts_rekening_perusahaan.sql

-- 1) Buat tabel bank_accounts jika belum ada (dengan semua kolom yang dibutuhkan)
CREATE TABLE IF NOT EXISTS `bank_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `account_code` VARCHAR(50) NOT NULL,
  `account_name` VARCHAR(150) NOT NULL,
  `account_number` VARCHAR(60) NULL,
  `office_code` VARCHAR(50) NULL,
  `branch` VARCHAR(120) NULL,
  `purpose` VARCHAR(20) NOT NULL DEFAULT 'RECEIVE',
  `currency` VARCHAR(10) NOT NULL DEFAULT 'IDR',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `note` TEXT NULL,
  `status` ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `gl_account_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bank_accounts_code` (`account_code`),
  KEY `idx_bank_accounts_acc_no` (`account_number`),
  KEY `idx_bank_accounts_purpose` (`purpose`),
  KEY `idx_bank_accounts_office` (`office_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2) Jika tabel sudah ada (dari 074), tambahkan kolom yang belum ada
SET @db = DATABASE();

-- account_number
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='account_number');
SET @sql = IF(@col_exists=0, 'ALTER TABLE bank_accounts ADD COLUMN account_number VARCHAR(60) NULL AFTER account_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- office_code
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='office_code');
SET @sql = IF(@col_exists=0, 'ALTER TABLE bank_accounts ADD COLUMN office_code VARCHAR(50) NULL AFTER account_number', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- branch
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='branch');
SET @sql = IF(@col_exists=0, 'ALTER TABLE bank_accounts ADD COLUMN branch VARCHAR(120) NULL AFTER office_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- purpose
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='purpose');
SET @sql = IF(@col_exists=0, "ALTER TABLE bank_accounts ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT 'RECEIVE' AFTER branch", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- is_active
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='is_active');
SET @sql = IF(@col_exists=0, 'ALTER TABLE bank_accounts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER purpose', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- note
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='note');
SET @sql = IF(@col_exists=0, 'ALTER TABLE bank_accounts ADD COLUMN note TEXT NULL AFTER is_active', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- updated_at
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='updated_at');
SET @sql = IF(@col_exists=0, 'ALTER TABLE bank_accounts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- status (jika belum ada)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='bank_accounts' AND column_name='status');
SET @sql = IF(@col_exists=0, "ALTER TABLE bank_accounts ADD COLUMN status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE' AFTER note", 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='bank_accounts' AND index_name='idx_bank_accounts_acc_no');
SET @sql = IF(@idx_exists=0, 'ALTER TABLE bank_accounts ADD INDEX idx_bank_accounts_acc_no (account_number)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=@db AND table_name='bank_accounts' AND index_name='idx_bank_accounts_purpose');
SET @sql = IF(@idx_exists=0, 'ALTER TABLE bank_accounts ADD INDEX idx_bank_accounts_purpose (purpose)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
