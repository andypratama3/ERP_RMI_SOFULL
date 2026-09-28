-- 132_master_manufactures_internal_office.sql
-- Kolom internal_office_code: jika manufacture = kantor internal (pembelian antar kantor),
-- set office_code kantor supplier. Saat Incoming, stock kantor supplier otomatis berkurang.
--
-- Contoh: Buat manufacture "Kantor BKS" dengan internal_office_code='BKS'.
-- BGR buat PO ke manufacture BKS → BGR terima Incoming → stock BKS berkurang, BGR bertambah.
--
-- Jalankan: mysql -u root -p erp_rmi_sofull < sql/migrations/132_master_manufactures_internal_office.sql

SET @db = DATABASE();

SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='master_manufactures' AND column_name='internal_office_code');
SET @sql = IF(@col=0, 'ALTER TABLE master_manufactures ADD COLUMN internal_office_code VARCHAR(30) NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
