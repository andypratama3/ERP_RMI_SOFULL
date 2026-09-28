-- 139_customer_portal_master_mpr_link.sql
-- Link customer_portal_users ke master_mpr (PIC)

SET FOREIGN_KEY_CHECKS=0;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'customer_portal_users' AND column_name = 'master_mpr_id') = 0,
  'ALTER TABLE customer_portal_users ADD COLUMN master_mpr_id INT DEFAULT NULL COMMENT ''FK master_mpr.id'' AFTER office_code',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS=1;
