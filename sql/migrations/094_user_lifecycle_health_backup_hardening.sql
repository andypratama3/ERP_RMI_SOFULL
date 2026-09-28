-- 094_user_lifecycle_health_backup_hardening.sql
-- Idempotent migration for:
-- - user lifecycle columns/indexes (master_system_login)
-- - audit support indexes
-- Safe to run multiple times.

SET @db_name := DATABASE();

-- normalize existing status values before enum hardening
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login'
    ),
    "UPDATE master_system_login SET status = CASE WHEN UPPER(TRIM(COALESCE(status,'')))='INACTIVE' THEN 'INACTIVE' ELSE 'ACTIVE' END",
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- enforce status enum contract
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.TABLES
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login'
    ),
    "ALTER TABLE master_system_login MODIFY COLUMN status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'",
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- master_system_login lifecycle columns
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login' AND COLUMN_NAME='deactivated_at'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN deactivated_at DATETIME NULL AFTER last_login_at'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login' AND COLUMN_NAME='deactivated_by'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN deactivated_by VARCHAR(120) NULL AFTER deactivated_at'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login' AND COLUMN_NAME='deleted_at'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN deleted_at DATETIME NULL AFTER deactivated_by'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login' AND COLUMN_NAME='deleted_by'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN deleted_by VARCHAR(120) NULL AFTER deleted_at'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login' AND COLUMN_NAME='delete_reason'
    ),
    'SELECT 1',
    'ALTER TABLE master_system_login ADD COLUMN delete_reason TEXT NULL AFTER deleted_by'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- indexes
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login' AND INDEX_NAME='idx_msl_status'
    ),
    'SELECT 1',
    'CREATE INDEX idx_msl_status ON master_system_login(status)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='master_system_login' AND INDEX_NAME='idx_msl_deleted_at'
    ),
    'SELECT 1',
    'CREATE INDEX idx_msl_deleted_at ON master_system_login(deleted_at)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Optional index to speed actor lookups in audit log
SET @sql := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA=@db_name AND TABLE_NAME='erp_audit_log' AND INDEX_NAME='idx_username_created'
    ),
    'SELECT 1',
    'CREATE INDEX idx_username_created ON erp_audit_log(username, created_at)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
