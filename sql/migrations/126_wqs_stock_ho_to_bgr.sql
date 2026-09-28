-- Migration 126: Pindahkan data office HO ke BGR (jika ada)
-- NOTE: Tidak menggunakan HO. Office code hanya BGR, BDG, BKS, TGR, SLO, SMG, KAL, JGY, SYS.
-- Untuk instalasi yang sudah jalankan migration 125 dengan HO.

-- Merge stock HO ke BGR, lalu hapus HO
INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
SELECT product_id, 'BGR', stock_qty, updated_at FROM wqs_stock_by_office WHERE office_code='HO'
ON DUPLICATE KEY UPDATE stock_qty = stock_qty + VALUES(stock_qty), updated_at = GREATEST(COALESCE(updated_at,'1970-01-01'), VALUES(updated_at));

DELETE FROM wqs_stock_by_office WHERE office_code='HO';
