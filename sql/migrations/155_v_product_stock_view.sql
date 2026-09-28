-- 155_v_product_stock_view.sql
-- Buat VIEW v_product_stock agar join ke wqs_stock selalu aman (tidak duplikat).
-- wqs_stock menyimpan stok per office/depo, sehingga LEFT JOIN langsung ke master_products
-- tanpa GROUP BY menghasilkan baris duplikat per produk.
--
-- Penggunaan yang BENAR:
--   LEFT JOIN v_product_stock s ON s.product_id = p.id
-- Penggunaan yang SALAH (jangan pakai):
--   LEFT JOIN wqs_stock s ON s.product_id = p.id   ← bisa duplikat!
--
-- View ini SELALU aman: 1 produk = 1 baris, stock_qty = total semua office/depo.

CREATE OR REPLACE VIEW `v_product_stock` AS
SELECT
    product_id,
    SUM(stock_qty)  AS stock_qty,
    MAX(updated_at) AS updated_at
FROM wqs_stock
GROUP BY product_id;
