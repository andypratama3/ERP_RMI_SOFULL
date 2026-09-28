-- Data Migration Fast Track — LEGACY
-- Step 3 (alternatif): post opening stock ke wqs_stock (tanpa office).
--
-- Gunakan ini HANYA jika wqs_stock_by_office belum ada dan migration 125 belum dijalankan.
-- Setelah posting, disarankan jalankan 125_wqs_stock_per_office.sql untuk migrasi ke multi-office.
--
-- Jalankan: mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < 003_legacy_wqs_stock.sql

SET @batch := 'CUTOVER_2026Q1';

START TRANSACTION;

-- Update existing
UPDATE wqs_stock ws
JOIN (
  SELECT p.id AS product_id, SUM(s.qty_on_hand) AS qty
  FROM mig_opening_stock_stg s
  JOIN master_products p ON UPPER(p.sku) = UPPER(s.sku)
  WHERE s.migration_batch = @batch
  GROUP BY p.id
) x ON x.product_id = ws.product_id
SET ws.stock_qty = x.qty, ws.updated_at = NOW();

-- Insert missing
INSERT INTO wqs_stock (product_id, stock_qty, updated_at)
SELECT x.product_id, x.qty, NOW()
FROM (
  SELECT p.id AS product_id, SUM(s.qty_on_hand) AS qty
  FROM mig_opening_stock_stg s
  JOIN master_products p ON UPPER(p.sku) = UPPER(s.sku)
  WHERE s.migration_batch = @batch
  GROUP BY p.id
) x
LEFT JOIN wqs_stock ws ON ws.product_id = x.product_id
WHERE ws.product_id IS NULL;

COMMIT;

-- Verifikasi
SELECT p.sku, p.products_name, ws.stock_qty
FROM wqs_stock ws
JOIN master_products p ON p.id = ws.product_id
ORDER BY p.sku
LIMIT 100;
