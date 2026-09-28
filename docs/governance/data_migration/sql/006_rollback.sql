-- Data Migration — ROLLBACK
-- Hapus data yang di-posting dari batch tertentu.
-- EDIT @batch sebelum jalankan!
--
-- PERINGATAN: Backup database dulu. Rollback bersifat destruktif.
--
-- Jalankan: mysql -h 127.0.0.1 -u root -p erp_rmi_sofull < 006_rollback.sql

SET @batch := 'CUTOVER_2026Q1';

START TRANSACTION;

-- 1. Hapus opening AR (sales_do dengan note marker)
DELETE FROM sales_do
WHERE note LIKE CONCAT('%OPENING_AR batch=', @batch, '%');

-- 2. Hapus opening AP (soft delete — set deleted_at)
UPDATE purchases_invoice_ap
SET deleted_at = NOW()
WHERE deleted_at IS NULL
  AND note LIKE CONCAT('%OPENING_AP batch=', @batch, '%');

-- 3. Stock: tidak bisa rollback otomatis (data sudah merge).
--    Tim harus restore dari backup atau reset manual.
--    Opsional: truncate staging untuk batch ini
DELETE FROM mig_opening_stock_stg WHERE migration_batch = @batch;
DELETE FROM mig_opening_ap_stg WHERE migration_batch = @batch;
DELETE FROM mig_opening_ar_stg WHERE migration_batch = @batch;

COMMIT;

-- Verifikasi: staging kosong untuk batch
SELECT 'Staging after rollback:' AS info;
SELECT migration_batch, COUNT(*) AS cnt FROM mig_opening_stock_stg GROUP BY migration_batch;
SELECT migration_batch, COUNT(*) AS cnt FROM mig_opening_ap_stg GROUP BY migration_batch;
SELECT migration_batch, COUNT(*) AS cnt FROM mig_opening_ar_stg GROUP BY migration_batch;
