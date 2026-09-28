/*
  032A_master_products_compat_add_columns.sql
  ============================================================
  Tujuan:
  - Fix mismatch schema `master_products` yang bikin halaman:
    - /master/master_products.php
    - /stock/wqs_stock_adjustment.php
    - /purchases/*
    error (contoh: Unknown column `p.manufacture_id`, missing `unit`, missing `status`, dst).

  Prinsip:
  - TANPA DROP TABLE.
  - Aman dijalankan berulang (cek INFORMATION_SCHEMA + dynamic SQL).
  - Mengisi (backfill) kolom baru dari kolom lama bila ada:
    - unit <- uom
    - status <- is_active
    - products_code <- sku

  Cara pakai:
  - phpMyAdmin -> pilih DB `ERP_RMI_SOFULL` -> tab SQL -> paste & Run.
*/

SET @db := DATABASE();

/* ------------------------------------------------------------
   ADD COLUMNS (jika belum ada)
------------------------------------------------------------ */

-- products_code
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='products_code'
);
SET @sql := IF(@c=0,
  'ALTER TABLE master_products ADD COLUMN products_code VARCHAR(50) NULL AFTER id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- type
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='type'
);
SET @sql := IF(@c=0,
  "ALTER TABLE master_products ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'ALKES' AFTER products_name",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- manufacture_id
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='manufacture_id'
);
SET @sql := IF(@c=0,
  'ALTER TABLE master_products ADD COLUMN manufacture_id INT(11) NULL AFTER type',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- vendor_id
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='vendor_id'
);
SET @sql := IF(@c=0,
  'ALTER TABLE master_products ADD COLUMN vendor_id INT(11) NULL AFTER manufacture_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- category_id
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='category_id'
);
SET @sql := IF(@c=0,
  'ALTER TABLE master_products ADD COLUMN category_id INT(11) NULL AFTER vendor_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- unit
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='unit'
);
SET @sql := IF(@c=0,
  "ALTER TABLE master_products ADD COLUMN unit VARCHAR(50) NOT NULL DEFAULT 'pcs' AFTER brand",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- price
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='price'
);
SET @sql := IF(@c=0,
  'ALTER TABLE master_products ADD COLUMN price DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER unit',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- status
SET @c := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='status'
);
SET @sql := IF(@c=0,
  "ALTER TABLE master_products ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER price",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ------------------------------------------------------------
   BACKFILL (isi kolom baru dari kolom lama bila ada)
------------------------------------------------------------ */

-- products_code <- sku
SET @sku_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='sku'
);
SET @code_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='products_code'
);
SET @sql := IF(@sku_exists=1 AND @code_exists=1,
  "UPDATE master_products SET products_code = sku WHERE (products_code IS NULL OR products_code='') AND (sku IS NOT NULL AND sku<>'')",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- unit <- uom (kalau kolom uom ada)
SET @uom_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='uom'
);
SET @unit_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='unit'
);
SET @sql := IF(@uom_exists=1 AND @unit_exists=1,
  "UPDATE master_products SET unit = uom WHERE (unit IS NULL OR unit='' OR unit='pcs') AND (uom IS NOT NULL AND uom<>'')",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- status <- is_active (kalau kolom is_active ada)
SET @ia_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='is_active'
);
SET @status_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='status'
);
SET @sql := IF(@ia_exists=1 AND @status_exists=1,
  "UPDATE master_products SET status = IF(is_active=1,'active','inactive') WHERE (status IS NULL OR status='')",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- type default (kalau kolom type ada)
SET @type_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='master_products' AND COLUMN_NAME='type'
);
SET @sql := IF(@type_exists=1,
  "UPDATE master_products SET type='ALKES' WHERE (type IS NULL OR type='')",
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

/* ------------------------------------------------------------
   DONE
------------------------------------------------------------ */

SELECT 'OK: 032A master_products compat migration selesai.' AS info;
