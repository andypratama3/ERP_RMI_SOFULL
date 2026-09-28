-- 148_vendor_category_logistic_to_scm.sql
-- Perbaikan: category "LOGISTIC" / "Forwarding LOGISTIC" salah — seharusnya kode Dept (SCM, PQP, dll).
-- LOGISTIC = vendor_type, bukan department. Vendor Logistic/Forwarding → SCM.

UPDATE master_vendors SET category = 'SCM' WHERE UPPER(TRIM(COALESCE(category,''))) = 'LOGISTIC';
UPDATE master_vendors SET category = 'SCM' WHERE UPPER(TRIM(COALESCE(category,''))) = 'FORWARDING LOGISTIC';
