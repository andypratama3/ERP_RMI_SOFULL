-- Data Migration Fast Track
-- Step 3: post opening stock from staging to wqs_stock_by_office.
--
-- Target: wqs_stock_by_office (office BGR = depo utama).
-- Jika tabel wqs_stock_by_office belum ada, jalankan dulu: sql/migrations/125_wqs_stock_per_office.sql
--
-- Jalankan: mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < 003_post_opening_stock.sql

SET @batch := 'CUTOVER_2026Q1';
SET @office := 'BGR';

START TRANSACTION;

-- Update existing product stock rows (BGR).
UPDATE wqs_stock_by_office ws
JOIN (
  SELECT p.id AS product_id, SUM(s.qty_on_hand) AS qty
  FROM mig_opening_stock_stg s
  JOIN master_products p ON UPPER(p.sku) = UPPER(s.sku)
  WHERE s.migration_batch = @batch
  GROUP BY p.id
) x ON x.product_id = ws.product_id AND ws.office_code = @office
SET ws.stock_qty = x.qty, ws.updated_at = NOW();

-- Insert missing product stock rows (BGR).
INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
SELECT x.product_id, @office, x.qty, NOW()
FROM (
  SELECT p.id AS product_id, SUM(s.qty_on_hand) AS qty
  FROM mig_opening_stock_stg s
  JOIN master_products p ON UPPER(p.sku) = UPPER(s.sku)
  WHERE s.migration_batch = @batch
  GROUP BY p.id
) x
LEFT JOIN wqs_stock_by_office ws ON ws.product_id = x.product_id AND ws.office_code = @office
WHERE ws.product_id IS NULL;

COMMIT;

-- Verifikasi
SELECT p.sku, p.products_name, ws.stock_qty, ws.office_code
FROM wqs_stock_by_office ws
JOIN master_products p ON p.id = ws.product_id
WHERE ws.office_code = @office
ORDER BY p.sku
LIMIT 100;
