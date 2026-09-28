-- 154_system_config_dedup.sql
-- Hapus duplikat PQP_EMAIL & CRM_EMAIL (RFQ/Customer Portal email)
-- Penyebab: INSERT IGNORE + office_code NULL → MySQL UNIQUE mengizinkan banyak NULL

-- 1. Hapus duplikat PQP_EMAIL (simpan yang id terkecil)
DELETE c2 FROM system_config c1
JOIN system_config c2 ON c1.config_key = c2.config_key
  AND (c1.office_code <=> c2.office_code)
  AND c1.config_group = c2.config_group
  AND c1.id < c2.id
WHERE c1.config_group = 'RFQ' AND c1.config_key = 'PQP_EMAIL';

-- 2. Hapus duplikat CRM_EMAIL (simpan yang id terkecil)
DELETE c2 FROM system_config c1
JOIN system_config c2 ON c1.config_key = c2.config_key
  AND (c1.office_code <=> c2.office_code)
  AND c1.config_group = c2.config_group
  AND c1.id < c2.id
WHERE c1.config_group = 'CUSTOMER_PORTAL' AND c1.config_key = 'CRM_EMAIL';
