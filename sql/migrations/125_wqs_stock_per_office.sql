-- Migration 125: Stock per office (branch)
-- Stock konsisten per branch. Tidak bisa lintas branch tanpa serah terima.
-- NOTE: Tidak menggunakan HO. Office code hanya BGR, BDG, BKS, TGR, SLO, SMG, KAL, JGY, SYS.
-- Migrates existing wqs_stock to office_code='BGR' (depo utama).

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS wqs_stock_by_office (
  product_id INT NOT NULL,
  office_code VARCHAR(30) NOT NULL DEFAULT 'BGR',
  stock_qty DECIMAL(18,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL,
  PRIMARY KEY (product_id, office_code),
  KEY idx_office (office_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrate existing wqs_stock to wqs_stock_by_office (office BGR)
INSERT INTO wqs_stock_by_office (product_id, office_code, stock_qty, updated_at)
SELECT product_id, 'BGR', COALESCE(stock_qty, 0), updated_at FROM wqs_stock
ON DUPLICATE KEY UPDATE stock_qty = VALUES(stock_qty), updated_at = VALUES(updated_at);

SET FOREIGN_KEY_CHECKS=1;
