-- Migration 144: master_vendors.office_code — cabang vendor (BGR, BKS, dll)
-- vendors_name otomatis + office code (contoh: Baraka Express BGR)
-- Konsisten dengan master_customers.office_code

SET @db = DATABASE();
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='master_vendors' AND column_name='office_code');
SET @sql = IF(@col=0, 'ALTER TABLE master_vendors ADD COLUMN office_code VARCHAR(10) DEFAULT NULL AFTER vendors_name', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
