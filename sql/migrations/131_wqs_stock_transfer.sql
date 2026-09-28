-- 131_wqs_stock_transfer.sql
-- Transfer / Mutasi antar kantor dengan bukti otentik (foto kartu stok + foto fisik produk)
--
-- Jalankan: mysql -u root -p erp_rmi_sofull < sql/migrations/131_wqs_stock_transfer.sql

SET @db = DATABASE();

-- Tabel header transfer
CREATE TABLE IF NOT EXISTS wqs_stock_transfer (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transfer_code VARCHAR(50) NOT NULL,
  from_office VARCHAR(30) NOT NULL,
  to_office VARCHAR(30) NOT NULL,
  transfer_date DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  note TEXT NULL,
  wqs_stock_before VARCHAR(255) NULL,
  wqs_stock_after VARCHAR(255) NULL,
  foto_fisik_keluar VARCHAR(255) NULL,
  wqs_stock_before_penerima VARCHAR(255) NULL,
  wqs_stock_after_penerima VARCHAR(255) NULL,
  foto_fisik_masuk VARCHAR(255) NULL,
  created_by VARCHAR(80) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  sent_by VARCHAR(80) NULL,
  received_at DATETIME NULL,
  received_by VARCHAR(80) NULL,
  UNIQUE KEY uq_transfer_code (transfer_code),
  KEY idx_from_office (from_office),
  KEY idx_to_office (to_office),
  KEY idx_transfer_date (transfer_date),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel detail items
CREATE TABLE IF NOT EXISTS wqs_stock_transfer_items (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT NOT NULL,
  product_id INT NOT NULL,
  sku VARCHAR(50) NOT NULL,
  product_name VARCHAR(255) NULL,
  unit VARCHAR(20) NULL,
  qty DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_transfer (transfer_id),
  KEY idx_product (product_id),
  CONSTRAINT fk_transfer_items_transfer FOREIGN KEY (transfer_id) REFERENCES wqs_stock_transfer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabel attachments untuk foto fisik detail (multiple per transfer)
CREATE TABLE IF NOT EXISTS wqs_stock_transfer_attachments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT NOT NULL,
  attachment_type VARCHAR(30) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  product_id INT NULL,
  caption VARCHAR(255) NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_transfer (transfer_id),
  KEY idx_type (attachment_type),
  CONSTRAINT fk_transfer_att_transfer FOREIGN KEY (transfer_id) REFERENCES wqs_stock_transfer(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
