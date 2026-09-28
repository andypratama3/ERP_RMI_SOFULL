-- 130_wqs_incoming_stock_card.sql
-- Tambah kolom foto kartu stok sebelum/sesudah di wqs_incoming (pola sama dengan sales_do)
-- Untuk baseline stock opname & investigasi selisih
--
-- Jalankan: mysql -u root -p erp_rmi_sofull < sql/migrations/130_wqs_incoming_stock_card.sql

SET @db = DATABASE();

-- wqs_stock_before
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_incoming' AND column_name='wqs_stock_before');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_incoming ADD COLUMN wqs_stock_before VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- wqs_stock_after
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_incoming' AND column_name='wqs_stock_after');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_incoming ADD COLUMN wqs_stock_after VARCHAR(255) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
