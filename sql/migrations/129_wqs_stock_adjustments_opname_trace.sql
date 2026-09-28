-- Migration 129: wqs_stock_adjustments — Traceability dari Stock Opname
-- Pastikan adj_code, office_code, created_by ada untuk link ke opname

SET @db = DATABASE();

-- adj_code (untuk link opname_code)
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_adjustments' AND column_name='adj_code');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_adjustments ADD COLUMN adj_code VARCHAR(80) NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- office_code (untuk stock per branch)
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_adjustments' AND column_name='office_code');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_adjustments ADD COLUMN office_code VARCHAR(32) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- created_by
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_adjustments' AND column_name='created_by');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_adjustments ADD COLUMN created_by VARCHAR(80) NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
