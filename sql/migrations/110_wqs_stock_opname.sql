-- Migration 110: Stock Opname (terjadwal setiap awal minggu oleh tim WQS)
-- Tables: wqs_stock_opname, wqs_stock_opname_items

CREATE TABLE IF NOT EXISTS wqs_stock_opname (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  opname_code VARCHAR(48) NOT NULL,
  opname_date DATE NOT NULL,
  office_code VARCHAR(32) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  note VARCHAR(500) NULL,
  created_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_by VARCHAR(80) NULL,
  applied_at DATETIME NULL,
  UNIQUE KEY uk_opname_code (opname_code),
  KEY idx_opname_date (opname_date),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wqs_stock_opname_items (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  opname_id INT NOT NULL,
  product_id INT NOT NULL,
  sku VARCHAR(80) NULL,
  product_name VARCHAR(255) NULL,
  unit VARCHAR(20) NULL,
  qty_system DECIMAL(18,2) NOT NULL DEFAULT 0,
  qty_fisik DECIMAL(18,2) NULL,
  delta_qty DECIMAL(18,2) NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_opname_id (opname_id),
  KEY idx_product (product_id),
  KEY idx_sku (sku),
  CONSTRAINT fk_opname_items_opname FOREIGN KEY (opname_id) REFERENCES wqs_stock_opname(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
