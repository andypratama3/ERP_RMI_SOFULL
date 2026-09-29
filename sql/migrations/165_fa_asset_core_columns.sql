-- 165_fa_asset_core_columns.sql
-- Register Asset memakai kolom asset_category_code / quantity / unit_cost
-- (Fixed_Asset/assets.php) tapi dump lama belum memilikinya -> fatal
-- "Unknown column 'quantity' in 'field list'". Idempotent: aman diulang.

SET @db := DATABASE();

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'fa_assets' AND COLUMN_NAME = 'asset_category_code') = 0,
  'ALTER TABLE fa_assets ADD COLUMN asset_category_code VARCHAR(50) NULL AFTER legacy_asset_code',
  'DO 0'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'fa_assets' AND COLUMN_NAME = 'quantity') = 0,
  'ALTER TABLE fa_assets ADD COLUMN quantity INT NOT NULL DEFAULT 1 AFTER asset_category_code',
  'DO 0'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'fa_assets' AND COLUMN_NAME = 'unit_cost') = 0,
  'ALTER TABLE fa_assets ADD COLUMN unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER quantity',
  'DO 0'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Rekap GL membaca gl_journal_lines.description (dashboards/finance/gl_rekap.php)
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'gl_journal_lines' AND COLUMN_NAME = 'description') = 0,
  'ALTER TABLE gl_journal_lines ADD COLUMN description VARCHAR(255) NULL AFTER line_no',
  'DO 0'));
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
