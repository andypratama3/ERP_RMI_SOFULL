-- 133_seed_pembelian_antar_kantor.sql
-- Seed Customer Internal & Manufacture Kantor Internal untuk flow Pembelian Antar Kantor.
-- Prasyarat: Migration 132 (internal_office_code di master_manufactures) sudah dijalankan.
--
-- Jalankan: mysql -u root -p erp_rmi_sofull < sql/migrations/133_seed_pembelian_antar_kantor.sql

SET @db = DATABASE();

-- Pastikan kolom internal_office_code ada
SET @col = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='master_manufactures' AND column_name='internal_office_code');
SET @sql = IF(@col=0, 'ALTER TABLE master_manufactures ADD COLUMN internal_office_code VARCHAR(30) NULL AFTER status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1. Customer Internal (OFFICE-INT) — dari master_office, skip HO
INSERT INTO master_customers (customers_code, name, customers_name, category, segment, city, office_code, status, created_at, updated_at)
SELECT
    CONCAT(UPPER(TRIM(o.office_code)), '-INT'),
    CONCAT('Kantor ', IFNULL(o.office_name, o.office_code), ' (Internal)'),
    CONCAT('Kantor ', IFNULL(o.office_name, o.office_code), ' (Internal)'),
    'Internal',
    'Kantor',
    '',
    UPPER(TRIM(o.office_code)),
    'active',
    NOW(),
    NOW()
FROM master_office o
WHERE o.office_code IS NOT NULL
  AND TRIM(o.office_code) != ''
  AND UPPER(TRIM(o.office_code)) != 'HO'
  AND NOT EXISTS (
    SELECT 1 FROM master_customers c
    WHERE c.customers_code = CONCAT(UPPER(TRIM(o.office_code)), '-INT')
  );

-- 2. Manufacture Kantor Internal (KANTOR-OFFICE) — status=1 (active), internal_office_code=office
INSERT INTO master_manufactures (manufactures_code, manufactures_name, manufacture_code, manufacture_name, status, internal_office_code, created_at, updated_at)
SELECT
    CONCAT('KANTOR-', UPPER(TRIM(o.office_code))),
    CONCAT('Kantor ', IFNULL(o.office_name, o.office_code)),
    CONCAT('KANTOR-', UPPER(TRIM(o.office_code))),
    CONCAT('Kantor ', IFNULL(o.office_name, o.office_code)),
    1,
    UPPER(TRIM(o.office_code)),
    NOW(),
    NOW()
FROM master_office o
WHERE o.office_code IS NOT NULL
  AND TRIM(o.office_code) != ''
  AND UPPER(TRIM(o.office_code)) != 'HO'
  AND NOT EXISTS (
    SELECT 1 FROM master_manufactures m
    WHERE m.internal_office_code = UPPER(TRIM(o.office_code))
  );
