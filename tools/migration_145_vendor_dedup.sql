-- Migration 145: Hapus vendor duplikat
-- FOR001 (PT NOATUM LOGISTIC) → hapus, referensi ke FOR008 (PT NOATUM LOGISTICS)
-- FOR004 (PT ZEIST GLOBAL SERVICE) → hapus, referensi ke FOR010 (PT ZEIST GLOBAL SERVICE)
--
-- Jalankan: mysql -u user -p database < tools/migration_145_vendor_dedup.sql
--
-- Catatan: Jika tabel/kolom tidak ada (mis. delivery_vendor_id), abaikan error.
-- Wajib dijalankan sebelum fitur vendors_name_normalized (unique constraint) aktif.

START TRANSACTION;

-- ============================================================
-- 1. FOR001 → FOR008 (PT NOATUM)
-- ============================================================
SELECT @keep_id := id FROM master_vendors WHERE vendors_code = 'FOR008' LIMIT 1;
SELECT @del_id := id FROM master_vendors WHERE vendors_code = 'FOR001' LIMIT 1;

UPDATE sales_do SET delivery_vendor_id = @keep_id WHERE delivery_vendor_id = @del_id;
UPDATE purchases_forwarder_invoice SET vendor_id = @keep_id WHERE vendor_id = @del_id;
UPDATE purchases_forwarder_quotes SET vendor_id = @keep_id WHERE vendor_id = @del_id;
UPDATE purchases_po SET forwarder_vendor_id = @keep_id WHERE forwarder_vendor_id = @del_id;

DELETE FROM master_vendors WHERE vendors_code = 'FOR001';

-- ============================================================
-- 2. FOR004 → FOR010 (PT ZEIST)
-- ============================================================
SELECT @keep_id := id FROM master_vendors WHERE vendors_code = 'FOR010' LIMIT 1;
SELECT @del_id := id FROM master_vendors WHERE vendors_code = 'FOR004' LIMIT 1;

UPDATE sales_do SET delivery_vendor_id = @keep_id WHERE delivery_vendor_id = @del_id;
UPDATE purchases_forwarder_invoice SET vendor_id = @keep_id WHERE vendor_id = @del_id;
UPDATE purchases_forwarder_quotes SET vendor_id = @keep_id WHERE vendor_id = @del_id;
UPDATE purchases_po SET forwarder_vendor_id = @keep_id WHERE forwarder_vendor_id = @del_id;

DELETE FROM master_vendors WHERE vendors_code = 'FOR004';

COMMIT;
