-- 087_p0_schema_hardening_runtime_ddl_cleanup.sql
-- Purpose:
-- 1) Move runtime DDL from application pages into idempotent migration
-- 2) Add FK-friendly columns for 3-way match accuracy (po_id / po_item_id)
-- 3) Keep backward compatibility (safe rerun)

CREATE TABLE IF NOT EXISTS `sales_do` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `do_code` varchar(50) NOT NULL,
  `tracking_code` varchar(50) DEFAULT NULL,
  `do_date` date NOT NULL,
  `customers_code` varchar(50) NOT NULL,
  `office_code` varchar(20) NOT NULL,
  `sales_emp_code` varchar(50) DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `customer_pic` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `total_amount` decimal(18,2) DEFAULT 0,
  `tax_code` varchar(20) DEFAULT NULL,
  `tax_rate_percent` decimal(5,2) DEFAULT 0,
  `tax_amount` decimal(18,2) DEFAULT 0,
  `grand_total` decimal(18,2) DEFAULT 0,
  `is_price_include_tax` tinyint(1) DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'crm_to_wqs',
  `crm_created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `crm_start_at` datetime DEFAULT NULL,
  `crm_finish_at` datetime DEFAULT NULL,
  `crm_duration_sec` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_do_code` (`do_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `sales_do_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `do_id` int(11) NOT NULL,
  `line_no` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` int(11) DEFAULT 0,
  `unit` varchar(20) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `serial_lot` varchar(120) DEFAULT NULL,
  `unit_price` decimal(18,2) DEFAULT 0,
  `disc_percent` decimal(5,2) DEFAULT 0,
  `subtotal` decimal(18,2) DEFAULT 0,
  `barcode` varchar(100) DEFAULT NULL,
  `stock_at_crm` int(11) DEFAULT NULL,
  `show_package_items` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_do` (`do_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wqs_incoming` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `incoming_code` VARCHAR(50) NOT NULL,
  `received_date` DATE NOT NULL,
  `po_id` INT NULL,
  `po_code` VARCHAR(60) NULL,
  `office_code` VARCHAR(30) NULL,
  `depo_name` VARCHAR(60) NULL,
  `ref_note` VARCHAR(255) NULL,
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

CREATE TABLE IF NOT EXISTS `wqs_stock` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `stock_qty` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_product` (`product_id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wqs_stock_baseline_lock` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `locked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wqs_stock_adjustments` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `sku` VARCHAR(50) NOT NULL,
  `delta_qty` INT NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_product` (`product_id`),
  KEY `idx_sku` (`sku`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- sales_do columns
SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tracking_code'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tracking_code VARCHAR(50) NULL AFTER do_code'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tax_code'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tax_code VARCHAR(20) NULL AFTER total_amount'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tax_rate_percent'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tax_rate_percent DECIMAL(5,2) DEFAULT 0 AFTER tax_code'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tax_amount'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tax_amount DECIMAL(18,2) DEFAULT 0 AFTER tax_rate_percent'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'grand_total'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN grand_total DECIMAL(18,2) DEFAULT 0 AFTER tax_amount'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'is_price_include_tax'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN is_price_include_tax TINYINT(1) DEFAULT 0 AFTER grand_total'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'crm_start_at'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN crm_start_at DATETIME NULL AFTER crm_created_at'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'crm_finish_at'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN crm_finish_at DATETIME NULL AFTER crm_start_at'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'crm_duration_sec'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN crm_duration_sec INT(11) NULL AFTER crm_finish_at'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sales_do_items columns
SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do_items' AND column_name = 'barcode'),
    'SELECT 1',
    'ALTER TABLE sales_do_items ADD COLUMN barcode VARCHAR(100) NULL AFTER subtotal'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do_items' AND column_name = 'stock_at_crm'),
    'SELECT 1',
    'ALTER TABLE sales_do_items ADD COLUMN stock_at_crm INT(11) NULL AFTER barcode'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do_items' AND column_name = 'show_package_items'),
    'SELECT 1',
    'ALTER TABLE sales_do_items ADD COLUMN show_package_items TINYINT(1) DEFAULT 0 AFTER stock_at_crm'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do_items' AND column_name = 'exp_date'),
    'SELECT 1',
    'ALTER TABLE sales_do_items ADD COLUMN exp_date DATE NULL AFTER unit'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do_items' AND column_name = 'serial_lot'),
    'SELECT 1',
    'ALTER TABLE sales_do_items ADD COLUMN serial_lot VARCHAR(120) NULL AFTER exp_date'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- wqs_incoming / wqs_incoming_items hardening
SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wqs_incoming' AND column_name = 'po_id'),
    'SELECT 1',
    'ALTER TABLE wqs_incoming ADD COLUMN po_id INT NULL AFTER received_date'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wqs_incoming' AND index_name = 'idx_po_id'),
    'SELECT 1',
    'ALTER TABLE wqs_incoming ADD INDEX idx_po_id (po_id)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'wqs_incoming_items' AND column_name = 'po_item_id'),
    'SELECT 1',
    'ALTER TABLE wqs_incoming_items ADD COLUMN po_item_id INT NULL AFTER incoming_id'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'wqs_incoming_items' AND index_name = 'idx_po_item_id'),
    'SELECT 1',
    'ALTER TABLE wqs_incoming_items ADD INDEX idx_po_item_id (po_item_id)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

