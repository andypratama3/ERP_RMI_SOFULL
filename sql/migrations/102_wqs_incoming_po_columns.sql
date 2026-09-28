-- 102_wqs_incoming_po_columns.sql
-- 1) Buat tabel wqs_incoming & wqs_incoming_items jika belum ada
-- 2) Tambah kolom po_id, po_item_id jika tabel sudah ada tapi kolom belum
--
-- Error: "Table wqs_incoming doesn't exist" atau "Kolom po_id belum tersedia" → jalankan file ini.
--
-- Cara jalankan:
--   mysql -u root -p erp_rmi_sofull < sql/migrations/102_wqs_incoming_po_columns.sql
--
-- Di NAS via SSH:
--   cd /volume4/web/ERP_RMI_SOFULL
--   mysql -u root -p erp_rmi_sofull < sql/migrations/102_wqs_incoming_po_columns.sql

-- ============================================================
-- BAGIAN 1: Buat tabel jika belum ada
-- ============================================================

CREATE TABLE IF NOT EXISTS `wqs_incoming` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `incoming_code` VARCHAR(50) NOT NULL,
  `received_date` DATE NOT NULL,
  `po_id` INT NULL,
  `po_code` VARCHAR(60) NULL,
  `office_code` VARCHAR(30) NULL,
  `depo_name` VARCHAR(60) NULL,
  `ref_note` VARCHAR(255) NULL,
  `deleted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_incoming_code` (`incoming_code`),
  KEY `idx_received_date` (`received_date`),
  KEY `idx_po_id` (`po_id`),
  KEY `idx_po` (`po_code`),
  KEY `idx_office` (`office_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wqs_incoming_items` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `incoming_id` INT NOT NULL,
  `po_item_id` INT NULL,
  `product_id` INT NOT NULL,
  `sku` VARCHAR(50) NOT NULL,
  `lot_number` VARCHAR(80) NULL,
  `serial_number` VARCHAR(80) NULL,
  `exp_date` DATE NULL,
  `qty` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_incoming` (`incoming_id`),
  KEY `idx_po_item_id` (`po_item_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- BAGIAN 2: Tambah kolom jika tabel sudah ada (skema lama)
-- Butuh MySQL 8.0.12+ / MariaDB 10.5.2+ untuk IF NOT EXISTS
-- Jika error "syntax error", gunakan: 102_wqs_incoming_legacy.sql
-- ============================================================

ALTER TABLE wqs_incoming ADD COLUMN IF NOT EXISTS po_id INT NULL;
ALTER TABLE wqs_incoming ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL;
ALTER TABLE wqs_incoming ADD INDEX IF NOT EXISTS idx_po_id (po_id);

ALTER TABLE wqs_incoming_items ADD COLUMN IF NOT EXISTS po_item_id INT NULL;
ALTER TABLE wqs_incoming_items ADD INDEX IF NOT EXISTS idx_po_item_id (po_item_id);
