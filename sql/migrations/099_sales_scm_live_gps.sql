-- 099_sales_scm_live_gps.sql
-- Live GPS check-in columns for SCM device/browser pings.

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'scm_live_lat'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN scm_live_lat DECIMAL(10,7) NULL AFTER fallback_live_location_url'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'scm_live_lng'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN scm_live_lng DECIMAL(10,7) NULL AFTER scm_live_lat'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'scm_live_accuracy_m'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN scm_live_accuracy_m DECIMAL(8,2) NULL AFTER scm_live_lng'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'scm_live_at'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN scm_live_at DATETIME NULL AFTER scm_live_accuracy_m'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND index_name = 'idx_sales_do_scm_live_at'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD INDEX idx_sales_do_scm_live_at (scm_live_at)'
  )
);
PREPARE stmt FROM @idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;
