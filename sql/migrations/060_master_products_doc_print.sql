-- sql/migrations/060_master_products_doc_print.sql
-- Master Products: docs + print logs
-- MySQL 8+ / MariaDB 10.4+ (phpMyAdmin ready)

SET @OLD_FK_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- UP
CREATE TABLE IF NOT EXISTS master_products_doc (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NOT NULL,
  doc_type VARCHAR(50) NOT NULL DEFAULT 'GENERAL',
  file_name VARCHAR(255) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  note VARCHAR(255) NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_product_id (product_id),
  KEY idx_doc_type (doc_type),
  KEY idx_uploaded_at (uploaded_at),
  KEY idx_is_deleted (is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS master_products_print (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id BIGINT UNSIGNED NULL,
  print_type VARCHAR(50) NOT NULL DEFAULT 'LIST',
  note VARCHAR(255) NULL,
  printed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  printed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_product_id (product_id),
  KEY idx_printed_at (printed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- FK (idempotent, hanya jika master_products ada)
SET @mp_exists := (
  SELECT COUNT(*)
  FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'master_products'
);

-- master_products_doc -> master_products
SET @fk_doc_exists := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE constraint_schema = DATABASE()
    AND table_name = 'master_products_doc'
    AND constraint_type = 'FOREIGN KEY'
    AND constraint_name = 'fk_master_products_doc_product'
);

SET @sql_fk_doc := IF(
  @mp_exists = 1 AND @fk_doc_exists = 0,
  'ALTER TABLE master_products_doc\n    ADD CONSTRAINT fk_master_products_doc_product\n    FOREIGN KEY (product_id) REFERENCES master_products(id)\n    ON DELETE CASCADE',
  'SELECT 1'
);

PREPARE stmt_fk_doc FROM @sql_fk_doc;
EXECUTE stmt_fk_doc;
DEALLOCATE PREPARE stmt_fk_doc;

-- master_products_print -> master_products
SET @fk_print_exists := (
  SELECT COUNT(*)
  FROM information_schema.table_constraints
  WHERE constraint_schema = DATABASE()
    AND table_name = 'master_products_print'
    AND constraint_type = 'FOREIGN KEY'
    AND constraint_name = 'fk_master_products_print_product'
);

SET @sql_fk_print := IF(
  @mp_exists = 1 AND @fk_print_exists = 0,
  'ALTER TABLE master_products_print\n    ADD CONSTRAINT fk_master_products_print_product\n    FOREIGN KEY (product_id) REFERENCES master_products(id)\n    ON DELETE SET NULL',
  'SELECT 1'
);

PREPARE stmt_fk_print FROM @sql_fk_print;
EXECUTE stmt_fk_print;
DEALLOCATE PREPARE stmt_fk_print;

-- DOWN (rollback)
-- DROP TABLE IF EXISTS master_products_print;
-- DROP TABLE IF EXISTS master_products_doc;

SET FOREIGN_KEY_CHECKS = @OLD_FK_CHECKS;
