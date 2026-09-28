-- 153_rfq_config_seed.sql
-- Seed config: RFQ currency rates & PQP/CRM email untuk notifikasi
-- Idempotent: pakai INSERT ... WHERE NOT EXISTS supaya tidak duplikat saat re-run

-- Currency rates (ke USD). Update via master_system_config.php jika perlu.
INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'RFQ_CURRENCY', 'rate_CNY', '0.14', NULL, 'CNY to USD (approx)', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_group='RFQ_CURRENCY' AND config_key='rate_CNY' AND office_code IS NULL);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'RFQ_CURRENCY', 'rate_IDR', '0.000063', NULL, 'IDR to USD (approx)', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_group='RFQ_CURRENCY' AND config_key='rate_IDR' AND office_code IS NULL);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'RFQ_CURRENCY', 'rate_EUR', '1.08', NULL, 'EUR to USD (approx)', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_group='RFQ_CURRENCY' AND config_key='rate_EUR' AND office_code IS NULL);

-- PQP email untuk notifikasi quotation (comma-separated)
INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'RFQ', 'PQP_EMAIL', 'pqp@rizqullahmediska.com,rizqullahmediskapqp@rizqullahmediskaindonesia.com', NULL, 'Email PQP untuk notifikasi RFQ (comma-separated)', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_group='RFQ' AND config_key='PQP_EMAIL' AND office_code IS NULL);

-- CRM email untuk notifikasi order dari Customer Portal (comma-separated)
INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'CUSTOMER_PORTAL', 'CRM_EMAIL', 'crm@rizqullahmediska.com,crm@rizqullahmediskaindonesia.com', NULL, 'Email CRM untuk notifikasi order portal (comma-separated)', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_group='CUSTOMER_PORTAL' AND config_key='CRM_EMAIL' AND office_code IS NULL);
