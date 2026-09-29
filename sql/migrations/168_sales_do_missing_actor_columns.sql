-- 168_sales_do_missing_actor_columns.sql
-- Kolom pelaku yang DITULIS kode tapi TIDAK ADA di skema, sehingga setiap
-- tulisannya gugur diam-diam dan print DO menampilkan "pelaku tidak tercatat":
--   wqs_started_by  <- sales/wqs_do_tasks.php + stock/wqs_do_tasks.php (WQS start)
--   fin_updated_by  <- sales/fin_do_tasks.php (save / approve_revision / paid)
--   crm_created_by  <- sales/sales_do.php (create / edit DO)
-- sales/sales_do_view.php sudah membaca ketiganya sebagai kandidat pelaku.
--
-- Idempoten: ADD COLUMN hanya bila kolom belum ada (pola migrasi 163).
-- Semua kolom NULL-able, tanpa default, tanpa DROP/ALTER kolom existing.
-- Data existing tidak diubah (kolom baru terisi NULL sampai aksi berikutnya).

SET FOREIGN_KEY_CHECKS=0;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'wqs_started_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN wqs_started_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'fin_updated_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN fin_updated_by VARCHAR(100) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'crm_created_by') = 0,
  'ALTER TABLE sales_do ADD COLUMN crm_created_by VARCHAR(50) NULL',
  'SELECT 1'
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS=1;
