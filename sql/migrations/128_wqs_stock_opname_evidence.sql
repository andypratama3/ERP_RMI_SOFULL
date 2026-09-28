-- Migration 128: Stock Opname — Bukti Otentik Validasi
-- - Verifikasi dua pihak (submit → verify → apply)
-- - Foto fisik (attachments)
-- - Tanda tangan/paraf verifikator

-- 1) Kolom verifikasi dua pihak & tanda tangan (MySQL: gunakan procedure untuk IF NOT EXISTS)
SET @db = DATABASE();

-- submitted_by
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_opname' AND column_name='submitted_by');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_opname ADD COLUMN submitted_by VARCHAR(80) NULL AFTER created_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- submitted_at
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_opname' AND column_name='submitted_at');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_opname ADD COLUMN submitted_at DATETIME NULL AFTER submitted_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- verified_by
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_opname' AND column_name='verified_by');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_opname ADD COLUMN verified_by VARCHAR(80) NULL AFTER applied_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- verified_at
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_opname' AND column_name='verified_at');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_opname ADD COLUMN verified_at DATETIME NULL AFTER verified_by', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- signature_path
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='wqs_stock_opname' AND column_name='signature_path');
SET @sql = IF(@col=0, 'ALTER TABLE wqs_stock_opname ADD COLUMN signature_path VARCHAR(255) NULL AFTER verified_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Tabel foto fisik (attachments)
CREATE TABLE IF NOT EXISTS wqs_stock_opname_attachments (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  opname_id INT NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  original_filename VARCHAR(255) NULL,
  mime_type VARCHAR(100) NULL,
  file_size INT NULL,
  caption VARCHAR(255) NULL,
  uploaded_by VARCHAR(80) NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_opname_id (opname_id),
  CONSTRAINT fk_opname_att_opname FOREIGN KEY (opname_id) REFERENCES wqs_stock_opname(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
