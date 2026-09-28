-- Data Migration Fast Track
-- Step 1: create staging tables for opening data.

CREATE TABLE IF NOT EXISTS mig_opening_stock_stg (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_batch VARCHAR(80) NOT NULL,
  row_no INT NOT NULL,
  sku VARCHAR(80) NOT NULL,
  qty_on_hand INT NOT NULL DEFAULT 0,
  loaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_batch (migration_batch),
  KEY idx_sku (sku)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mig_opening_ap_stg (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_batch VARCHAR(80) NOT NULL,
  row_no INT NOT NULL,
  invoice_number VARCHAR(120) NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE DEFAULT NULL,
  manufacture_code VARCHAR(60) NOT NULL,
  office_code VARCHAR(20) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
  balance_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) DEFAULT NULL,
  loaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_batch (migration_batch),
  KEY idx_inv (invoice_number),
  KEY idx_mnf (manufacture_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mig_opening_ar_stg (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  migration_batch VARCHAR(80) NOT NULL,
  row_no INT NOT NULL,
  invoice_number VARCHAR(120) NOT NULL,
  invoice_date DATE NOT NULL,
  due_date DATE DEFAULT NULL,
  customers_code VARCHAR(60) NOT NULL,
  office_code VARCHAR(20) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
  balance_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  note VARCHAR(255) DEFAULT NULL,
  loaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_batch (migration_batch),
  KEY idx_inv (invoice_number),
  KEY idx_cust (customers_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: clean previous batch before load
-- DELETE FROM mig_opening_stock_stg WHERE migration_batch='CUTOVER_2026Q1';
-- DELETE FROM mig_opening_ap_stg WHERE migration_batch='CUTOVER_2026Q1';
-- DELETE FROM mig_opening_ar_stg WHERE migration_batch='CUTOVER_2026Q1';

