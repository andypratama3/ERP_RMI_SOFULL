-- 088_master_import_and_bank_source_unification.sql
-- Purpose:
-- 1) Logging table for CSV import runs + row-level errors
-- 2) Unify bank account source of truth to bank_accounts table
-- 3) Safe rerun / idempotent

CREATE TABLE IF NOT EXISTS `master_import_runs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module` VARCHAR(40) NOT NULL,
  `file_name` VARCHAR(255) NULL,
  `status` ENUM('PREVIEW','DONE','FAILED') NOT NULL DEFAULT 'PREVIEW',
  `rows_total` INT NOT NULL DEFAULT 0,
  `rows_success` INT NOT NULL DEFAULT 0,
  `rows_failed` INT NOT NULL DEFAULT 0,
  `created_by` BIGINT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_master_import_runs_module` (`module`),
  KEY `idx_master_import_runs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `master_import_run_errors` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `run_id` BIGINT UNSIGNED NOT NULL,
  `row_no` INT NOT NULL,
  `external_key` VARCHAR(120) NULL,
  `error_message` VARCHAR(255) NOT NULL,
  `row_payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_master_import_err_run` (`run_id`),
  KEY `idx_master_import_err_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Extend bank_accounts as single source of truth
SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = 'account_number'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD COLUMN account_number VARCHAR(60) NULL AFTER account_name'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = 'office_code'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD COLUMN office_code VARCHAR(50) NULL AFTER account_number'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = 'branch'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD COLUMN branch VARCHAR(120) NULL AFTER office_code'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = 'purpose'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD COLUMN purpose VARCHAR(20) NOT NULL DEFAULT ''RECEIVE'' AFTER branch'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = 'is_active'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER purpose'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = 'note'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD COLUMN note TEXT NULL AFTER is_active'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND column_name = 'updated_at'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Keep status and is_active aligned
UPDATE bank_accounts
SET is_active = CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END
WHERE (status IN ('ACTIVE','INACTIVE'));

UPDATE bank_accounts
SET status = CASE WHEN is_active = 1 THEN 'ACTIVE' ELSE 'INACTIVE' END
WHERE status IN ('ACTIVE','INACTIVE');

-- Backfill from legacy master_company_bank_accounts when exists
SET @has_legacy := (
  SELECT COUNT(*) FROM information_schema.tables
  WHERE table_schema = DATABASE()
    AND table_name = 'master_company_bank_accounts'
);

SET @sql_legacy := IF(
  @has_legacy > 0,
  "INSERT INTO bank_accounts
   (account_code, account_name, account_number, office_code, branch, purpose, currency, is_active, note, status, created_at, updated_at)
   SELECT
     CONCAT('CBA-', c.id),
     c.account_name,
     c.account_number,
     c.office_code,
     c.branch,
     COALESCE(NULLIF(c.purpose,''), 'RECEIVE'),
     COALESCE(NULLIF(c.currency,''), 'IDR'),
     COALESCE(c.is_active, 1),
     c.note,
     CASE WHEN COALESCE(c.is_active,1)=1 THEN 'ACTIVE' ELSE 'INACTIVE' END,
     COALESCE(c.created_at, NOW()),
     COALESCE(c.updated_at, NOW())
   FROM master_company_bank_accounts c
   LEFT JOIN bank_accounts b
     ON b.account_number = c.account_number
    AND b.account_name = c.account_name
   WHERE b.id IS NULL",
  "SELECT 1"
);
PREPARE stmt FROM @sql_legacy; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND index_name = 'idx_bank_accounts_acc_no'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD INDEX idx_bank_accounts_acc_no (account_number)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'bank_accounts' AND index_name = 'idx_bank_accounts_purpose'),
    'SELECT 1',
    'ALTER TABLE bank_accounts ADD INDEX idx_bank_accounts_purpose (purpose)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

