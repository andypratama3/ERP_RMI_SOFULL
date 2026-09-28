-- 125_sales_do_actor_columns.sql
-- Kolom pelaku (*_by) untuk sales_do — pasangan dari kolom *_at yang sudah ada.
-- PHP memakai pola bersyarat table_has_column($pdo,'sales_do','<kolom>') /
-- ensure_column(...), sehingga migrasi ini hanya menambah kolom yang belum ada.
-- Idempoten: aman dijalankan berulang (cek information_schema.columns / statistics).
-- Semua kolom NULL-able, tanpa default, tanpa DROP/ALTER kolom existing.

SET FOREIGN_KEY_CHECKS=0;

-- WQS actor columns
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'wqs_ready_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN wqs_ready_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'wqs_completed_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN wqs_completed_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'wqs_updated_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN wqs_updated_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'wqs_cancelled_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN wqs_cancelled_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- SCM actor columns
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'scm_on_delivery_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN scm_on_delivery_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'scm_delivered_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN scm_delivered_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'scm_updated_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN scm_updated_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ACT / FIN actor columns (NULL-able, aman bila view belum memakai)
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'act_reviewed_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN act_reviewed_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'fin_approved_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN fin_approved_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'fin_paid_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN fin_paid_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexes (biasa, idempoten via information_schema.statistics)
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND index_name = 'idx_sales_do_wqs_ready_by') = 0,
  'ALTER TABLE sales_do ADD INDEX idx_sales_do_wqs_ready_by (wqs_ready_by)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND index_name = 'idx_sales_do_scm_delivered_by') = 0,
  'ALTER TABLE sales_do ADD INDEX idx_sales_do_scm_delivered_by (scm_delivered_by)',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS=1;
