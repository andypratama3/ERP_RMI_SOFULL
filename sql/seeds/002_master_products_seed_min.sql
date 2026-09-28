-- 002_master_products_seed_min.sql
-- Seed minimal (aman re-run). Pastikan migration 001 sudah di-import.

INSERT IGNORE INTO `master_products`
  (`sku`, `products_name`, `uom`, `category`, `brand`, `is_active`)
VALUES
  ('SKU-DEMO-001', 'DEMO Product 1', 'PCS', 'DEMO', 'GENERIC', 1),
  ('SKU-DEMO-002', 'DEMO Product 2', 'PCS', 'DEMO', 'GENERIC', 1),
  ('SKU-DEMO-003', 'DEMO Product 3', 'BOX', 'DEMO', 'GENERIC', 1),
  ('SKU-DEMO-004', 'DEMO Product 4', 'PCS', 'DEMO', 'GENERIC', 1),
  ('SKU-DEMO-005', 'DEMO Product 5', 'SET', 'DEMO', 'GENERIC', 1);
