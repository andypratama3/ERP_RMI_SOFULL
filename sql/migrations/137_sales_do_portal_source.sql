-- 137_sales_do_portal_source.sql
-- Tambah kolom source & portal_user_id untuk tracking order dari Customer Portal
-- MySQL 8.0.12+ / MariaDB 10.5.2+ support ADD COLUMN IF NOT EXISTS

SET FOREIGN_KEY_CHECKS=0;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'source') = 0,
  'ALTER TABLE sales_do ADD COLUMN source VARCHAR(30) DEFAULT ''crm'' COMMENT ''crm|portal|api|marketplace''',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'portal_user_id') = 0,
  'ALTER TABLE sales_do ADD COLUMN portal_user_id INT DEFAULT NULL COMMENT ''FK customer_portal_users.id''',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS=1;
